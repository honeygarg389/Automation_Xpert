<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_brand_profiles', function (Blueprint $table): void {
            $table->string('legal_business_name', 180)->nullable()->after('brand_name');
            $table->text('registered_business_address')->nullable()->after('legal_business_name');
            $table->string('website', 255)->nullable()->after('thank_you_note');
        });

        Schema::table('restaurant_outlets', function (Blueprint $table): void {
            // `address` remains the outlet's billing address. These fields are
            // current outlet profile data, never retroactive fiscal snapshots.
            $table->string('public_email', 255)->nullable()->after('public_phone');
            $table->string('gstin', 15)->nullable()->after('public_website');
            $table->string('fssai_number', 14)->nullable()->after('gstin');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_outlets', function (Blueprint $table): void {
            $table->dropColumn(['public_email', 'gstin', 'fssai_number']);
        });

        Schema::table('restaurant_brand_profiles', function (Blueprint $table): void {
            $table->dropColumn(['legal_business_name', 'registered_business_address', 'website']);
        });
    }
};
