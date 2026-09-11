<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant foundation — legal document version registry.
 *
 * ─── WHY THIS EXISTS INSTEAD OF REUSING CmsPage ─────────────────────────────
 *
 * Investigated first: CmsPage (app/Models/CmsPage.php, cms_pages table) is a
 * PLAIN MUTABLE ROW. There is no revisions table, no versioned rows, and no
 * immutable-snapshot mechanism anywhere in the codebase — confirmed by a
 * repo-wide grep for revision/version in relation to cms_page, which returned
 * nothing. An `update()` on a CmsPage silently rewrites `content` in place with
 * no history preserved. A legal acceptance recorded against a CmsPage row
 * would therefore point at content that can change out from under it — the
 * exact defect this table exists to rule out. So `content_body` is stored
 * DIRECTLY here, as an immutable snapshot, rather than referencing CmsPage.
 *
 * ─── published_slot: THE CONCURRENCY-SAFE SINGLE-PUBLISHED INVARIANT ───────
 *
 * `status` alone cannot be protected by a UNIQUE constraint — MySQL has no
 * partial/filtered unique index, so `UNIQUE (document_type, status)` would
 * reject a second 'draft' or 'retired' row just as hard as a second
 * 'published' one. `published_slot` exists purely to make the invariant
 * expressible: it is NULL for every draft/retired row (and MySQL treats each
 * NULL as distinct, so any number of those coexist), and exactly 1 for the one
 * published row per document_type. `UNIQUE (document_type, published_slot)`
 * then rejects a second published row at the DB layer, independent of
 * whether the application code that got it there was correct.
 *
 * The CHECK constraint below keeps `status` and `published_slot` from ever
 * drifting apart, so `status` stays a trustworthy human-readable label instead
 * of a second, unenforced source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_document_versions', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 32);
            $table->string('version', 32);
            $table->string('status', 20)->default('draft');
            $table->longText('content_body');
            $table->char('content_sha256', 64);
            $table->unsignedTinyInteger('published_slot')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()
                ->constrained('admin_users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_type', 'version']);
            $table->unique(['document_type', 'published_slot']);
        });

        // MySQL 9.3 (this environment) supports CHECK constraints since 8.0.16.
        // Enforced independently of the application — see
        // LegalDocumentVersionPublishingTest for direct-write rejection tests,
        // and LegalDocumentPublishingService::publish() for why the
        // service-level transaction+lock is ALSO required, not a substitute.
        //
        // ⚠️ `published_slot = 1` (plain equality) rather than `<=>` here would
        // be a silent no-op for exactly the row that matters: with
        // status='published' AND published_slot=NULL, `published_slot = 1`
        // evaluates to NULL (not FALSE) in SQL's three-valued logic, so
        // `NULL OR (status != 'published' AND ...)` evaluates to NULL —
        // and MySQL's CHECK only rejects a row when the expression is FALSE,
        // not NULL. It was written that way first, verified against a direct
        // insert, and found to silently accept the violation it exists to
        // reject — `<=>` (NULL-safe equal) makes that branch FALSE instead of
        // NULL, so the OR only reduces to FALSE when the row is genuinely bad.
        DB::statement(<<<'SQL'
            ALTER TABLE legal_document_versions
            ADD CONSTRAINT legal_document_versions_status_slot_consistency
            CHECK (
                (status = 'published' AND published_slot <=> 1)
                OR (status != 'published' AND published_slot IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_document_versions');
    }
};
