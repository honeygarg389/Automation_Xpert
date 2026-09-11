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
 * Task 8, #42 — migrate → migrate:rollback → migrate again, confirm a clean
 * state. Rolls back exactly the 8 Restaurant-foundation migration files
 * (they carry the newest timestamps in the whole migrations set, so they are
 * the last batch RefreshDatabase's migrate:fresh ran) and re-applies them.
 */
class RestaurantMigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    private const RESTAURANT_MIGRATION_COUNT = 8;

    #[Test]
    public function the_restaurant_foundation_migrations_roll_back_and_reapply_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('legal_document_versions'));
        $this->assertTrue(Schema::hasTable('legal_acceptances'));
        $this->assertTrue(Schema::hasTable('restaurant_outlets'));
        $this->assertTrue(Schema::hasTable('pos_connections'));
        $this->assertTrue(Schema::hasTable('pos_webhook_events'));
        $this->assertTrue(Schema::hasColumn('contacts', 'whatsapp_consent_at'));
        $this->assertTrue(Schema::hasColumn('workspaces', 'client_mode'));
        $this->assertTrue(Schema::hasColumn('audit_logs', 'workspace_id'));

        Artisan::call('migrate:rollback', ['--step' => self::RESTAURANT_MIGRATION_COUNT]);

        $this->assertFalse(Schema::hasTable('legal_document_versions'));
        $this->assertFalse(Schema::hasTable('legal_acceptances'));
        $this->assertFalse(Schema::hasTable('restaurant_outlets'));
        $this->assertFalse(Schema::hasTable('pos_connections'));
        $this->assertFalse(Schema::hasTable('pos_webhook_events'));
        $this->assertFalse(Schema::hasColumn('contacts', 'whatsapp_consent_at'));
        $this->assertFalse(Schema::hasColumn('workspaces', 'client_mode'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'workspace_id'));

        // The rest of the schema (from before this branch) must be untouched.
        $this->assertTrue(Schema::hasTable('contacts'));
        $this->assertTrue(Schema::hasTable('workspaces'));

        Artisan::call('migrate');

        $this->assertTrue(Schema::hasTable('legal_document_versions'));
        $this->assertTrue(Schema::hasTable('legal_acceptances'));
        $this->assertTrue(Schema::hasTable('restaurant_outlets'));
        $this->assertTrue(Schema::hasTable('pos_connections'));
        $this->assertTrue(Schema::hasTable('pos_webhook_events'));
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
