<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ⚠️ COLUMN ORDER IS (created_at, action), NOT (action, created_at) — and
     * this was verified with EXPLAIN against a seeded scratch table before
     * being decided, not assumed from "the filter column goes first".
     *
     * The query this serves is `WHERE action LIKE 'smart_qr.%' ORDER BY
     * created_at DESC LIMIT N` — a prefix filter over a MINORITY of a
     * whole-application audit table, wanting only the most recent few matches.
     *
     * (action, created_at) puts every matching row in one contiguous index
     * range, but `created_at` is only sorted WITHIN each distinct `action`
     * value — smart_qr.assigned, smart_qr.batch_created, etc. are different
     * strings, so the combined result is NOT globally created_at-ordered.
     * MySQL has to collect every matching row and sort it (`Using filesort`)
     * before LIMIT can be applied. Measured on 100k rows (~5% smart_qr.*):
     * `rows=9146` examined, filesort present.
     *
     * (created_at, action) is already in the ORDER BY's sort order, so MySQL
     * walks it backward and evaluates the `action` condition per entry via
     * index condition pushdown, stopping as soon as 10 matches are found — no
     * sort, no full-range materialization. Same seed: `rows=10` examined,
     * "Backward index scan", no filesort.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['created_at', 'action']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'action']);
        });
    }
};
