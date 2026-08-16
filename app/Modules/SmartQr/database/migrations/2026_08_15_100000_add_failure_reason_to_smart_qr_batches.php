<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Slice 2 needs somewhere to record WHY a batch generation failed.
 *
 * Additive, and appended last — naming a position with ->after() forces a full
 * table rebuild where appending allows ALGORITHM=INSTANT.
 *
 * ⚠️ Slice 1 did not anticipate this column. The spec asks for "record failure
 * reason if a batch generation partially fails", and slice 1 designed the table
 * from the spec's batch field list, which does not include one. Recorded here
 * rather than quietly added, because it means the spec's own field list is
 * incomplete against its own requirements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('smart_qr_batches', function (Blueprint $table) {
            $table->text('failure_reason')->nullable();
            $table->timestamp('failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('smart_qr_batches', function (Blueprint $table) {
            $table->dropColumn(['failure_reason', 'failed_at']);
        });
    }
};
