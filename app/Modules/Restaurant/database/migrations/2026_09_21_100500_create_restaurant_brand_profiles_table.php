<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_brand_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('brand_name', 128)->nullable();
            $table->string('logo_path', 512)->nullable();
            $table->string('logo_disk', 64)->nullable();
            $table->string('cover_path', 512)->nullable();
            $table->string('cover_disk', 64)->nullable();
            $table->char('primary_color', 7)->nullable();
            $table->text('thank_you_note')->nullable();
            $table->json('social_links')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_brand_profiles');
    }
};
