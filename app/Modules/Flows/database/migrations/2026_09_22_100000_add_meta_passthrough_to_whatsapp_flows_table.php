<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            // Section D (imported-flow round-trip fix) — flow-level Meta Flow
            // JSON keys the visual builder has no concept of and never edits
            // (routing_model, data_api_version, data_channel_uri: the
            // data-exchange/dynamic-endpoint metadata a Flow can carry).
            // decompile() captures these on import so a later compile() can
            // re-emit them unchanged instead of silently dropping them —
            // without this, importing a Flow that uses Meta's data-exchange
            // feature and then editing/re-syncing it would permanently strip
            // that wiring. Null for the overwhelmingly common case (a purely
            // static Flow with none of these keys).
            $table->json('meta_passthrough')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            $table->dropColumn('meta_passthrough');
        });
    }
};
