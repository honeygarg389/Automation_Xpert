<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The materialized entitlement read model.
 *
 * Deferred until now deliberately: a cache built before its oracle makes a wrong
 * answer impossible to attribute between a resolver bug and a staleness bug. The
 * resolver has been proven by the canary since slice 2, so this can be a cache
 * with a known-good source of truth behind it.
 *
 * ⚠️ Dropping every row in this table must change NO answer. It is a
 * performance structure, not a correctness one, and a test asserts exactly that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_entitlements', function (Blueprint $table) {
            // The workspace IS the key. One row per workspace, no surrogate id —
            // a surrogate would permit two rows for one workspace, and the
            // second would be a silent cross-answer.
            $table->unsignedBigInteger('workspace_id')->primary();

            // limits + flags, exactly as Entitlement carries them.
            $table->json('payload');

            /**
             * ⚠️ THE NEXT MOMENT THIS ROW BECOMES WRONG, computed rather than guessed.
             *
             * Three inputs change the answer with NO WRITE ANYWHERE, so no event
             * can ever fire for them:
             *
             *   grant starts_at   a future-dated grant becomes in force
             *   grant ends_at     a grant lapses
             *   subscription ends_at (both tables)
             *
             * `starts_at` is the nastiest and the reason a freshness heuristic
             * cannot work: the row is written AFTER the grant already exists, so
             * "recompute if the cache is older than the grant" looks satisfied
             * while the grant is still dormant. Only an absolute boundary catches
             * it.
             *
             * NULL means no dated input exists — an entitlement with no expiring
             * grant and no ending subscription genuinely has no boundary, and
             * those rows fall back to a flat TTL.
             */
            $table->timestamp('valid_until')->nullable()->index();

            $table->timestamp('computed_at');

            // Digest of the INPUTS (plan, subscription ids+statuses, grant
            // ids+statuses, partner id+mode). The sweep compares this instead of
            // recomputing everything, and statuses are inside it deliberately —
            // an id-only hash would miss a status change, which is exactly the
            // case the sweep exists to catch.
            $table->string('source_hash', 64)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_entitlements');
    }
};
