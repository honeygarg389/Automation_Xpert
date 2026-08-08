<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0, slice 9 — the two orphans.
 *
 * `onboarding_steps` (BUG-009) and `webhook_endpoints` are customer-owned data
 * keyed only to a USER. Both are reachable at `/app/...`, both are per-tenant in
 * meaning, and neither could take the workspace scope without a column.
 *
 * ─── Why the unique index change is part of the FIX, not an adjacent tidy ────
 *
 * `onboarding_steps` carries UNIQUE (user_id, step) — a per-USER unique on a
 * value that is about to become per-WORKSPACE.
 *
 * `OnboardingService::markStep()` writes with
 * `updateOrCreate(['user_id' => …, 'step' => …], …)`. Add `workspace_id` and
 * leave that index alone, and the moment a user completes the same step in a
 * SECOND workspace the insert collides on the old key. The feature would break
 * at exactly the point BUG-009 was supposed to start working.
 *
 * So the index is widened to (user_id, workspace_id, step) in the same
 * migration. Splitting them would ship a window in which the column exists and
 * the constraint contradicts it.
 *
 * This is the "non-composite unique on a per-workspace value" class: the fix is
 * a composite index, not a code change.
 *
 * ─── Attribution ─────────────────────────────────────────────────────────────
 *
 * A row's workspace is its owner's home workspace (`users.workspace_id`).
 * Unambiguous for a single-workspace user. For a multi-workspace user the row
 * records no workspace at all, so which one it meant is NOT recoverable — the
 * pre-flight below reports those rather than guessing.
 *
 * Measured before writing this: both tables have ZERO rows on this machine, so
 * nothing is attributed or lost here. The pre-flight is for deployed installs.
 */
return new class extends Migration
{
    /** @var array<string, string> table => the unique index that must be widened, if any */
    private const TABLES = [
        'onboarding_steps' => 'onboarding_steps_user_id_step_unique',
        'webhook_endpoints' => '',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('workspace_id')->nullable()->after('user_id');
                $t->index('workspace_id');
            });

            // Backfill from the owner. One statement, no chunking needed —
            // these tables are small by nature (a handful of rows per user).
            DB::statement("
                UPDATE {$table} AS t
                JOIN users AS u ON u.id = t.user_id
                SET t.workspace_id = u.workspace_id
                WHERE t.workspace_id IS NULL AND u.workspace_id IS NOT NULL
            ");
        }

        $this->abortOnUnattributableRows();

        // Widen the unique BEFORE NOT NULL, so a deployed install never sits in
        // a state where the column is required and the constraint contradicts it.
        //
        // ⚠️ CREATE THE NEW INDEX FIRST, THEN DROP THE OLD ONE. Dropping first
        // fails outright:
        //
        //   SQLSTATE[HY000] 1553: Cannot drop index
        //   'onboarding_steps_user_id_step_unique': needed in a foreign key
        //   constraint
        //
        // MySQL requires an index on a foreign-key column, and the old
        // (user_id, step) unique was the only one covering `user_id`. The new
        // (user_id, workspace_id, step) unique also LEADS with user_id, so it
        // serves the FK — but only once it exists. In this order the foreign key
        // is never left without an index, so the statement is safe to run
        // against a live table.
        Schema::table('onboarding_steps', function (Blueprint $t) {
            $t->unique(['user_id', 'workspace_id', 'step'], 'onboarding_steps_user_workspace_step_unique');
        });

        Schema::table('onboarding_steps', function (Blueprint $t) {
            $t->dropUnique(self::TABLES['onboarding_steps']);
        });

        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('workspace_id')->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        // Same ordering rule in reverse — see up().
        Schema::table('onboarding_steps', function (Blueprint $t) {
            $t->unique(['user_id', 'step'], self::TABLES['onboarding_steps']);
        });

        Schema::table('onboarding_steps', function (Blueprint $t) {
            $t->dropUnique('onboarding_steps_user_workspace_step_unique');
        });

        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                // dropIndex('name') — a STRING. Passing an ARRAY means "these
                // columns", from which Laravel DERIVES a name, so
                // dropIndex([$table.'_workspace_id_index']) asks to drop
                // `onboarding_steps_onboarding_steps_workspace_id_index_index`,
                // which does not exist. The rollback test is what caught it;
                // down() had never been run.
                $t->dropIndex($table.'_workspace_id_index');
                $t->dropColumn('workspace_id');
            });
        }
    }

    /**
     * Pre-flight, in the BUG-019 shape.
     *
     * A row whose owner has no home workspace cannot be attributed, and this
     * migration will not guess: assigning the wrong workspace to an onboarding
     * row is cosmetic, but assigning the wrong one to a WEBHOOK ENDPOINT points
     * a customer's outbound webhook at another tenant's events.
     */
    private function abortOnUnattributableRows(): void
    {
        $problems = [];

        foreach (array_keys(self::TABLES) as $table) {
            $count = DB::table($table)->whereNull('workspace_id')->count();

            if ($count > 0) {
                $userIds = DB::table($table)->whereNull('workspace_id')
                    ->distinct()->pluck('user_id')->take(20)->implode(', ');
                $problems[] = "  {$table}: {$count} row(s) unattributable (user_id: {$userIds}…)";
            }
        }

        if ($problems === []) {
            return;
        }

        throw new RuntimeException(implode(PHP_EOL, [
            '',
            'MIGRATION ABORTED — rows whose workspace cannot be derived.',
            '',
            implode(PHP_EOL, $problems),
            '',
            'These rows belong to users with no home workspace (users.workspace_id IS NULL).',
            'This migration will not guess. Assigning the wrong workspace to a webhook endpoint',
            'points a customer\'s outbound webhook at another tenant\'s events.',
            '',
            'Decide per row, set workspace_id by hand, then re-run.',
            '',
        ]));
    }
};
