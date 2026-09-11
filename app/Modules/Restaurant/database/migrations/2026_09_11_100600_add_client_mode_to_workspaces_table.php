<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `client_mode` controls onboarding-flow QUESTIONS only ('marketing_only' vs
 * 'pos_integrated'). It does NOT determine which POS provider(s) are
 * connected — that lives entirely on pos_connections. NULL means not yet
 * determined.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('client_mode', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('client_mode');
        });
    }
};
