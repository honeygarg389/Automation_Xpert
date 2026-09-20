<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_outlets', function (Blueprint $table): void {
            $table->string('public_phone', 32)->nullable()->after('address');
            $table->string('public_website', 255)->nullable()->after('public_phone');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_outlets', function (Blueprint $table): void {
            $table->dropColumn(['public_phone', 'public_website']);
        });
    }
};
