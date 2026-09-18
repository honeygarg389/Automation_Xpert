<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            // Null means unlimited — the common case, and the reason this is
            // nullable rather than defaulting to a large integer.
            $table->unsignedInteger('max_submissions')->nullable();
            $table->string('limit_error_message', 255)->nullable()
                ->default('You have already reached the maximum number of submissions for this form.');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            $table->dropColumn(['max_submissions', 'limit_error_message']);
        });
    }
};
