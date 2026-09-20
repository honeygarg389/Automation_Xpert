<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 messaging settings groundwork only.
 *
 * These are independent outlet preferences, not a delivery mechanism: adding
 * them must never dispatch work or make an external request. Database defaults
 * make every existing and future outlet opt out until an authorized operator
 * explicitly enables an individual setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_outlets', function (Blueprint $table): void {
            $table->boolean('digital_bill_enabled')->default(false)->after('status');
            $table->boolean('feedback_request_enabled')->default(false)->after('digital_bill_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_outlets', function (Blueprint $table): void {
            $table->dropColumn(['digital_bill_enabled', 'feedback_request_enabled']);
        });
    }
};
