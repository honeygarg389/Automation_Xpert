<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `pos_webhook_events.raw_body` is `MEDIUMBLOB` (pure bytes, no charset)
 * specifically so it CANNOT be altered by encoding/collation. Proved
 * directly against the model/DB layer, not through the HTTP controller: a
 * genuinely invalid-UTF-8 byte string would fail JSON decoding at the
 * controller's very first step (rejected as malformed_payload, which never
 * reaches pos_webhook_events at all) — so the only way to prove THIS
 * column's byte fidelity, independent of what the controller's business
 * logic happens to accept, is to write arbitrary bytes to it directly and
 * read them back.
 */
class PosWebhookEventRawBodyByteFidelityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function raw_body_round_trips_arbitrary_bytes_including_invalid_utf8(): void
    {
        $connection = PosConnection::factory()->create();

        // Null bytes, high-bit bytes, and a deliberately invalid UTF-8
        // sequence (0xFF 0xFE is not valid UTF-8 in any position) — exactly
        // what a TEXT/MEDIUMTEXT column with a utf8mb4 charset could not be
        // relied on to store unaltered.
        $bytes = "\x00\xFF\xFE\x80\x81".random_bytes(256).'{"trailing":"json-shaped bytes, irrelevant to this test"}';
        $this->assertFalse(mb_check_encoding($bytes, 'UTF-8'), 'Fixture must actually be invalid UTF-8 for this test to mean anything.');

        $event = PosWebhookEvent::create([
            'connection_id' => $connection->id,
            'workspace_id' => $connection->workspace_id,
            'provider' => 'petpooja',
            'payload_hash' => hash('sha256', $bytes),
            'event_type' => 'orderdetails',
            'received_at' => now(),
            'processing_status' => PosWebhookEvent::STATUS_PENDING,
            'raw_payload' => ['note' => 'byte fidelity test'],
            'raw_body' => $bytes,
            'attempts' => 0,
        ]);

        // A fresh model instance, forcing a real read from the DB rather than
        // trusting the in-memory attribute set by create().
        $fresh = PosWebhookEvent::query()->find($event->id);

        $this->assertSame(strlen($bytes), strlen($fresh->raw_body), 'Byte length must be identical — no truncation, no charset expansion/collapse.');
        $this->assertSame($bytes, $fresh->raw_body, 'raw_body must round-trip byte-for-byte, including invalid UTF-8.');
    }

    #[Test]
    public function raw_body_column_is_a_binary_type_with_no_charset(): void
    {
        $column = DB::selectOne(
            "SELECT DATA_TYPE, CHARACTER_SET_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_webhook_events' AND COLUMN_NAME = 'raw_body'"
        );

        $this->assertSame('mediumblob', strtolower($column->DATA_TYPE));
        $this->assertNull($column->CHARACTER_SET_NAME, 'A binary column must have NO character set at all.');
    }
}
