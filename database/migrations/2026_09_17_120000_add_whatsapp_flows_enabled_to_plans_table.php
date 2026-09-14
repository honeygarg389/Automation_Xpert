<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Preserve the ungated status quo for existing plans. */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->boolean('whatsapp_flows_enabled')->default(true)->after('white_label_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_flows_enabled');
        });
    }
};
