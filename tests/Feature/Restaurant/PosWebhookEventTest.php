<?php

namespace Tests\Feature\Restaurant;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Workspace;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 5 — the NULL-dedup acceptance for pos_webhook_events, and the
 * workspace()-relation-without-scope pattern.
 */
class PosWebhookEventTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function pos_webhook_event_does_not_use_the_belongs_to_workspace_trait(): void
    {
        $this->assertNotContains(BelongsToWorkspace::class, class_uses_recursive(PosWebhookEvent::class),
            'A quarantined event with no resolvable tenant must remain writable/visible.');
    }

    #[Test]
    public function an_exact_retry_with_a_real_connection_is_rejected_at_the_db_level(): void
    {
        $connection = PosConnection::factory()->create();

        PosWebhookEvent::factory()->create([
            'connection_id' => $connection->id,
            'payload_hash' => 'same-hash',
        ]);

        $this->expectException(QueryException::class);

        PosWebhookEvent::factory()->create([
            'connection_id' => $connection->id,
            'payload_hash' => 'same-hash',
        ]);
    }

    /**
     * ⚠️ THE ACCEPTED NULL-DEDUP GAP. Two quarantined events (no resolvable
     * connection) with the IDENTICAL payload_hash are NOT deduplicated,
     * because MySQL treats each NULL in connection_id as distinct for the
     * UNIQUE (connection_id, payload_hash) constraint. This is accepted as
     * low-risk — an unresolvable event has no tenant to double-charge or
     * double-notify — and this test pins that it is accepted, not merely
     * unnoticed.
     */
    #[Test]
    public function two_quarantined_events_with_no_connection_and_the_same_payload_hash_both_persist(): void
    {
        $first = PosWebhookEvent::factory()->create([
            'connection_id' => null,
            'payload_hash' => 'identical-hash',
        ]);
        $second = PosWebhookEvent::factory()->create([
            'connection_id' => null,
            'payload_hash' => 'identical-hash',
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, PosWebhookEvent::query()->where('payload_hash', 'identical-hash')->count());
    }

    #[Test]
    public function workspace_relation_resolves_correctly_with_no_automatic_scope_applied(): void
    {
        ['workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['workspace' => $workspaceB] = $this->createWorkspaceContext();

        PosWebhookEvent::factory()->create(['workspace_id' => $workspaceA->id, 'payload_hash' => 'h-a']);
        PosWebhookEvent::factory()->create(['workspace_id' => $workspaceB->id, 'payload_hash' => 'h-b']);

        $all = PosWebhookEvent::query()->get();
        $this->assertCount(2, $all, 'A cross-tenant query must not be silently filtered.');

        $eventA = $all->firstWhere('payload_hash', 'h-a');

        $this->assertInstanceOf(BelongsTo::class, $eventA->workspace());
        $this->assertInstanceOf(Workspace::class, $eventA->workspace()->first());
        $this->assertSame($workspaceA->id, $eventA->workspace()->first()->id);
    }
}
