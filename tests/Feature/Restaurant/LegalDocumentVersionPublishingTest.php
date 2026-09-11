<?php

namespace Tests\Feature\Restaurant;

use App\Modules\Restaurant\Exceptions\ImmutableLegalDocumentException;
use App\Modules\Restaurant\Models\LegalDocumentVersion;
use App\Modules\Restaurant\Services\LegalDocumentPublishingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 1 — the published_slot + CHECK constraint invariant, and the
 * atomic publish/retire service.
 */
class LegalDocumentVersionPublishingTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LegalDocumentPublishingService
    {
        return app(LegalDocumentPublishingService::class);
    }

    #[Test]
    public function current_published_is_null_when_nothing_has_been_published(): void
    {
        LegalDocumentVersion::factory()->create([
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'version' => 'v1',
        ]);

        $this->assertNull(LegalDocumentVersion::currentPublished(LegalDocumentVersion::TYPE_TERMS));
    }

    #[Test]
    public function publish_atomically_retires_the_previous_version_and_publishes_the_new_one(): void
    {
        $v1 = LegalDocumentVersion::factory()->create([
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'version' => 'v1',
        ]);
        $this->service()->publish($v1);

        $v2 = LegalDocumentVersion::factory()->create([
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'version' => 'v2',
        ]);
        $this->service()->publish($v2);

        $v1->refresh();
        $v2->refresh();

        $this->assertSame(LegalDocumentVersion::STATUS_RETIRED, $v1->status);
        $this->assertNull($v1->published_slot);
        $this->assertNotNull($v1->retired_at);

        $this->assertSame(LegalDocumentVersion::STATUS_PUBLISHED, $v2->status);
        $this->assertSame(1, $v2->published_slot);
        $this->assertNotNull($v2->published_at);

        $current = LegalDocumentVersion::currentPublished(LegalDocumentVersion::TYPE_TERMS);
        $this->assertNotNull($current);
        $this->assertTrue($current->is($v2));
    }

    #[Test]
    public function content_sha256_is_computed_at_write_time_and_verifies_the_stored_body(): void
    {
        $version = LegalDocumentVersion::factory()->create([
            'content_body' => 'the exact legal text',
        ]);

        $this->assertSame(hash('sha256', 'the exact legal text'), $version->content_sha256);

        $raw = DB::table('legal_document_versions')->where('id', $version->id)->first();
        $this->assertSame(hash('sha256', $raw->content_body), $raw->content_sha256,
            'content_sha256 must verify content_body as stored at the DB layer.');
    }

    /**
     * ⚠️ A failure partway through publish() must leave the PRE-publish state
     * intact: the old version still published, the new one never touched.
     * Forced via a `saving` listener that throws only when the NEW version's
     * row is about to be saved — i.e. AFTER the old version's retire-save has
     * already run inside the same transaction, so this proves the retire is
     * rolled back too, not merely that the publish never happened.
     */
    #[Test]
    public function a_failure_mid_publish_leaves_the_database_in_the_pre_publish_state(): void
    {
        $v1 = LegalDocumentVersion::factory()->create([
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'version' => 'v1',
        ]);
        $this->service()->publish($v1);

        $marker = 'FORCE-FAILURE-MARKER-'.uniqid();
        $v2 = LegalDocumentVersion::factory()->create([
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'version' => 'v2',
            'content_body' => $marker,
        ]);

        LegalDocumentVersion::saving(function (LegalDocumentVersion $model) use ($marker) {
            if ($model->content_body === $marker && $model->published_slot === LegalDocumentVersion::PUBLISHED_SLOT) {
                throw new \RuntimeException('Simulated failure mid-publish.');
            }
        });

        try {
            $this->service()->publish($v2);
            $this->fail('Expected the simulated failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated failure mid-publish.', $e->getMessage());
        }

        $v1->refresh();
        $v2->refresh();

        $this->assertSame(LegalDocumentVersion::STATUS_PUBLISHED, $v1->status,
            'The old version must still be published after a rolled-back publish.');
        $this->assertSame(1, $v1->published_slot);
        $this->assertNull($v1->retired_at);

        $this->assertSame(LegalDocumentVersion::STATUS_DRAFT, $v2->status,
            'The new version must not have been left half-published.');
        $this->assertNull($v2->published_slot);
        $this->assertNull($v2->published_at);

        $publishedCount = DB::table('legal_document_versions')
            ->where('document_type', LegalDocumentVersion::TYPE_TERMS)
            ->where('published_slot', LegalDocumentVersion::PUBLISHED_SLOT)
            ->count();
        $this->assertSame(1, $publishedCount, 'No zero-published or dual-published state.');
    }

    /**
     * The DB-level backstop, independent of the service. A raw write that
     * sets status/published_slot inconsistently must be rejected by MySQL
     * itself, not merely by application code choosing not to do it.
     */
    #[Test]
    public function the_check_constraint_rejects_a_raw_write_with_inconsistent_status_and_slot(): void
    {
        $this->expectException(QueryException::class);

        DB::table('legal_document_versions')->insert([
            'document_type' => LegalDocumentVersion::TYPE_DPA,
            'version' => 'bad-1',
            'status' => LegalDocumentVersion::STATUS_PUBLISHED,
            'published_slot' => null, // violates: published without slot=1
            'content_body' => 'x',
            'content_sha256' => hash('sha256', 'x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function the_check_constraint_also_rejects_a_draft_row_holding_the_published_slot(): void
    {
        $this->expectException(QueryException::class);

        DB::table('legal_document_versions')->insert([
            'document_type' => LegalDocumentVersion::TYPE_DPA,
            'version' => 'bad-2',
            'status' => LegalDocumentVersion::STATUS_DRAFT,
            'published_slot' => 1, // violates: slot=1 without status=published
            'content_body' => 'x',
            'content_sha256' => hash('sha256', 'x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The UNIQUE (document_type, published_slot) backstop: a second row
     * cannot hold published_slot=1 for the same document_type, independent
     * of the service or the CHECK constraint.
     */
    #[Test]
    public function the_unique_constraint_rejects_a_second_published_row_for_the_same_document_type(): void
    {
        $v1 = LegalDocumentVersion::factory()->create(['document_type' => LegalDocumentVersion::TYPE_TERMS]);
        $this->service()->publish($v1);

        $this->expectException(QueryException::class);

        DB::table('legal_document_versions')->insert([
            'document_type' => LegalDocumentVersion::TYPE_TERMS,
            'version' => 'v-conflict',
            'status' => LegalDocumentVersion::STATUS_PUBLISHED,
            'published_slot' => 1,
            'content_body' => 'x',
            'content_sha256' => hash('sha256', 'x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // The genuine cross-connection concurrency test lives in
    // LegalDocumentVersionConcurrencyTest — it cannot use RefreshDatabase's
    // per-test wrapping transaction (see that file's docblock for why).

    // ══ Immutability enforcement (pre-commit addendum) ═══════════════════

    #[Test]
    public function a_draft_can_be_edited_freely(): void
    {
        $version = LegalDocumentVersion::factory()->create([
            'status' => LegalDocumentVersion::STATUS_DRAFT,
            'content_body' => 'first draft',
        ]);

        $version->update([
            'document_type' => LegalDocumentVersion::TYPE_DPA,
            'version' => 'v-edited',
            'content_body' => 'edited draft text',
        ]);

        $fresh = $version->fresh();
        $this->assertSame(LegalDocumentVersion::TYPE_DPA, $fresh->document_type);
        $this->assertSame('v-edited', $fresh->version);
        $this->assertSame('edited draft text', $fresh->content_body);
        $this->assertSame(hash('sha256', 'edited draft text'), $fresh->content_sha256);
    }

    #[Test]
    public function editing_content_body_after_publish_throws(): void
    {
        $version = LegalDocumentVersion::factory()->create();
        $this->service()->publish($version);

        $this->expectException(ImmutableLegalDocumentException::class);

        $version->update(['content_body' => 'sneaky post-publish edit']);
    }

    #[Test]
    public function editing_document_type_or_version_after_publish_also_throws(): void
    {
        $version = LegalDocumentVersion::factory()->create();
        $this->service()->publish($version);

        try {
            $version->update(['version' => 'v-sneaky']);
            $this->fail('Expected ImmutableLegalDocumentException.');
        } catch (ImmutableLegalDocumentException $e) {
            $this->assertStringContainsString('version', $e->getMessage());
        }

        try {
            $version->fresh()->update(['document_type' => LegalDocumentVersion::TYPE_DPA]);
            $this->fail('Expected ImmutableLegalDocumentException.');
        } catch (ImmutableLegalDocumentException $e) {
            $this->assertStringContainsString('document_type', $e->getMessage());
        }

        $this->assertNotSame('v-sneaky', $version->fresh()->version);
        $this->assertNotSame(LegalDocumentVersion::TYPE_DPA, $version->fresh()->document_type);
    }

    #[Test]
    public function editing_content_body_after_retire_also_throws(): void
    {
        $v1 = LegalDocumentVersion::factory()->create();
        $this->service()->publish($v1);

        $v2 = LegalDocumentVersion::factory()->create(['document_type' => $v1->document_type]);
        $this->service()->publish($v2);

        $this->assertSame(LegalDocumentVersion::STATUS_RETIRED, $v1->fresh()->status);

        $this->expectException(ImmutableLegalDocumentException::class);

        $v1->fresh()->update(['content_body' => 'sneaky post-retire edit']);
    }

    /**
     * The guard must not interfere with the service's own legitimate writes
     * to status/published_slot/published_at/retired_at on an already
     * non-draft row — those are exactly what publish() does when retiring
     * the previous version.
     */
    #[Test]
    public function the_guard_does_not_block_the_services_own_status_transitions(): void
    {
        $v1 = LegalDocumentVersion::factory()->create();
        $this->service()->publish($v1);

        $v2 = LegalDocumentVersion::factory()->create(['document_type' => $v1->document_type]);
        $this->service()->publish($v2);

        $this->assertSame(LegalDocumentVersion::STATUS_RETIRED, $v1->fresh()->status);
        $this->assertSame(LegalDocumentVersion::STATUS_PUBLISHED, $v2->fresh()->status);
    }

    // ══ content_sha256 as a fully derived value (pre-commit addendum) ═════

    /**
     * Reassigning content_sha256 to the value it already has (because
     * content_body did not change) must not register as a dirty attribute —
     * verified, not assumed, since the unconditional recompute in
     * booted() now runs on every save regardless of whether content_body
     * changed.
     */
    #[Test]
    public function content_sha256_reassignment_is_a_no_op_when_content_body_is_unchanged(): void
    {
        $version = LegalDocumentVersion::factory()->create(['content_body' => 'stable text']);
        $version->refresh();

        $version->document_type = LegalDocumentVersion::TYPE_DPA;
        $this->assertTrue($version->isDirty('document_type'));
        $this->assertFalse($version->isDirty('content_sha256'),
            'content_sha256 must not be marked dirty merely because the model is being saved — '.
            'only when it actually changes value.');

        $version->save();

        $this->assertSame(hash('sha256', 'stable text'), $version->fresh()->content_sha256);
    }

    /**
     * ⚠️ THE EXACT PROOF THE TASK REQUIRES: a direct attempt to alter ONLY
     * content_sha256 on a PUBLISHED version — leaving content_body/
     * document_type/version untouched, so the immutability guard has
     * nothing to block — must still fail to persist a false hash. The save
     * itself succeeds (nothing dirty trips the guard), but the derived
     * recompute silently overwrites the false value before it ever reaches
     * the database.
     */
    #[Test]
    public function directly_setting_a_false_content_sha256_on_a_published_version_cannot_persist(): void
    {
        $version = LegalDocumentVersion::factory()->create(['content_body' => 'the real legal text']);
        $this->service()->publish($version);

        $version->content_sha256 = 'deadbeef-not-a-real-hash-'.str_repeat('0', 40);
        $version->save();

        $this->assertSame(
            hash('sha256', 'the real legal text'),
            $version->fresh()->content_sha256,
            'A direct write to content_sha256 must never survive a save — it is fully derived.'
        );
    }
}
