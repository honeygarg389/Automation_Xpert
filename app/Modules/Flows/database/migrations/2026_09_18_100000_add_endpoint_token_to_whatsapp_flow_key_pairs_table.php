<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_flow_key_pairs', function (Blueprint $table): void {
            // Nullable only for the in-migration backfill below; every existing
            // and newly-created key pair receives a random 256-bit token.
            $table->string('endpoint_token', 64)->nullable()->unique()->after('uuid');
        });

        DB::table('whatsapp_flow_key_pairs')->orderBy('id')->each(function (object $keyPair): void {
            DB::table('whatsapp_flow_key_pairs')
                ->where('id', $keyPair->id)
                ->update(['endpoint_token' => bin2hex(random_bytes(32))]);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flow_key_pairs', function (Blueprint $table): void {
            $table->dropUnique(['endpoint_token']);
            $table->dropColumn('endpoint_token');
        });
    }
};
