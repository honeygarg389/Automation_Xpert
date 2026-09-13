<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1C — sandbox/production environment marker on pos_connections.
 *
 * Default 'sandbox': every connection that already exists (Phase 1A/1B test
 * fixtures; there are no real customer connections yet) becomes sandbox,
 * which is correct — production activation does not exist as a concept
 * anywhere in the codebase yet. Phase 1C's admin UI creates ONLY sandbox
 * connections; there is deliberately no code path in this phase that can
 * ever write 'production' to this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_connections', function (Blueprint $table) {
            $table->string('environment', 20)->default('sandbox');
        });
    }

    public function down(): void
    {
        Schema::table('pos_connections', function (Blueprint $table) {
            $table->dropColumn('environment');
        });
    }
};
