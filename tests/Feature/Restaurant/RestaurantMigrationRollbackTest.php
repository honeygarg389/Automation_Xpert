<?php

namespace Tests\Feature\Restaurant;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 8, #42 (Phase 1A) + Phase 1B addendum — migrate → migrate:rollback →
 * migrate again, confirm a clean state. Targets the Restaurant module's
 * migrations by PATH, not by counting files.
 *
 * ⚠️ WHY --path, NOT A HARDCODED COUNT. The original version rolled back a
 * fixed `--step` count (8 in Phase 1A). Phase 1B added 2 more migrations
 * (raw_body, pos_webhook_rejections) and that count silently went stale:
 * rolling back only 8 of the (by then) 10 undid the 8 MOST RECENT
 * migrations, which left the two OLDEST Phase 1A tables
 * (legal_document_versions, legal_acceptances) still applied — caught by
 * this test itself failing, not predicted in advance.
 *
 * A dynamic `glob()`-based count would fix THIS instance but keeps the same
 * shape of bug alive: it assumes the Restaurant module's migrations are
 * exactly "the N most recently run migrations", which is only true because
 * no other module happens to have a later timestamp right now. `--path`
 * removes that assumption entirely — Laravel filters rollback/migrate to
 * migrations physically located under the given directory, independent of
 * how many there are or where they sort against any other module's. A
 * future Restaurant migration is included automatically; nothing here needs
 * to change when one is added.
 */
class RestaurantMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const RESTAURANT_MIGRATIONS_PATH = 'app/Modules/Restaurant/database/migrations';

    #[Test]
    public function the_restaurant_foundation_migrations_roll_back_and_reapply_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('legal_document_versions'));
        $this->assertTrue(Schema::hasTable('legal_acceptances'));
        $this->assertTrue(Schema::hasTable('restaurant_outlets'));
        $this->assertTrue(Schema::hasTable('pos_connections'));
        $this->assertTrue(Schema::hasTable('pos_webhook_events'));
        $this->assertTrue(Schema::hasColumn('pos_webhook_events', 'raw_body'));
        $this->assertTrue(Schema::hasTable('pos_webhook_rejections'));
        $this->assertTrue(Schema::hasColumn('contacts', 'whatsapp_consent_at'));
        $this->assertTrue(Schema::hasColumn('workspaces', 'client_mode'));
        $this->assertTrue(Schema::hasColumn('audit_logs', 'workspace_id'));

        Artisan::call('migrate:rollback', ['--path' => self::RESTAURANT_MIGRATIONS_PATH]);

        $this->assertFalse(Schema::hasTable('legal_document_versions'));
        $this->assertFalse(Schema::hasTable('legal_acceptances'));
        $this->assertFalse(Schema::hasTable('restaurant_outlets'));
        $this->assertFalse(Schema::hasTable('pos_connections'));
        $this->assertFalse(Schema::hasTable('pos_webhook_events'));
        $this->assertFalse(Schema::hasTable('pos_webhook_rejections'));
        $this->assertFalse(Schema::hasColumn('contacts', 'whatsapp_consent_at'));
        $this->assertFalse(Schema::hasColumn('workspaces', 'client_mode'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'workspace_id'));

        // The rest of the schema (from before this branch) must be untouched.
        $this->assertTrue(Schema::hasTable('contacts'));
        $this->assertTrue(Schema::hasTable('workspaces'));

        Artisan::call('migrate', ['--path' => self::RESTAURANT_MIGRATIONS_PATH]);

        $this->assertTrue(Schema::hasTable('legal_document_versions'));
        $this->assertTrue(Schema::hasTable('legal_acceptances'));
        $this->assertTrue(Schema::hasTable('restaurant_outlets'));
        $this->assertTrue(Schema::hasTable('pos_connections'));
        $this->assertTrue(Schema::hasTable('pos_webhook_events'));
        $this->assertTrue(Schema::hasColumn('pos_webhook_events', 'raw_body'));
        $this->assertTrue(Schema::hasTable('pos_webhook_rejections'));
        $this->assertTrue(Schema::hasColumn('contacts', 'whatsapp_consent_at'));
        $this->assertTrue(Schema::hasColumn('workspaces', 'client_mode'));
        $this->assertTrue(Schema::hasColumn('audit_logs', 'workspace_id'));

        // The CHECK constraint must have been recreated by the reapplied
        // migration, not silently dropped — prove it still rejects.
        $this->expectException(QueryException::class);
        DB::table('legal_document_versions')->insert([
            'document_type' => 'terms',
            'version' => 'rollback-check',
            'status' => 'published',
            'published_slot' => null,
            'content_body' => 'x',
            'content_sha256' => hash('sha256', 'x'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
