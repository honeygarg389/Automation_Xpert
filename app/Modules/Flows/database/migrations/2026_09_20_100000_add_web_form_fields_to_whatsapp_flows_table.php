<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            // Public forms are deliberately opt-in. The slug is opaque rather
            // than derived from authoring data, so it cannot be enumerated.
            $table->boolean('web_form_enabled')->default(false);
            $table->string('public_slug', 64)->nullable()->unique();
            $table->boolean('recaptcha_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            $table->dropUnique('whatsapp_flows_public_slug_unique');
            $table->dropColumn(['web_form_enabled', 'public_slug', 'recaptcha_enabled']);
        });
    }
};
