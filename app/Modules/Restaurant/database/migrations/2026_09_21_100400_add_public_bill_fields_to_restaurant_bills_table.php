<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_bills', function (Blueprint $table): void {
            // Nullable deliberately: historical bills are not backfilled into a
            // new bearer-link surface. New model/ingestion writes generate it.
            $table->char('public_token', 64)->nullable()->unique('restaurant_bills_public_token_unique')->after('external_order_id');
            $table->timestamp('public_access_revoked_at')->nullable()->after('public_token');
            $table->string('source_order_type', 64)->nullable()->after('source_order_status');
            $table->char('currency_code', 3)->nullable()->after('tax_total');
            $table->string('currency_symbol', 12)->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_bills', function (Blueprint $table): void {
            $table->dropUnique('restaurant_bills_public_token_unique');
            $table->dropColumn(['public_token', 'public_access_revoked_at', 'source_order_type', 'currency_code', 'currency_symbol']);
        });
    }
};
