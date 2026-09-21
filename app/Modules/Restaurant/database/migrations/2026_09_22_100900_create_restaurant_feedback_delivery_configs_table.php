<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_feedback_delivery_configs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->index('workspace_id', 'rfdc_workspace_idx');
            $table->foreign('workspace_id', 'rfdc_workspace_fk')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreignId('outlet_id')->unique()->constrained('restaurant_outlets')->cascadeOnDelete();
            $table->unsignedBigInteger('whatsapp_phone_number_id')->nullable();
            $table->index('whatsapp_phone_number_id', 'rfdc_phone_idx');
            $table->foreign('whatsapp_phone_number_id', 'rfdc_phone_fk')->references('id')->on('whatsapp_phone_numbers')->nullOnDelete();
            $table->unsignedBigInteger('whatsapp_template_id')->nullable();
            $table->index('whatsapp_template_id', 'rfdc_template_idx');
            $table->foreign('whatsapp_template_id', 'rfdc_template_fk')->references('id')->on('whatsapp_templates')->nullOnDelete();
            $table->string('timing_preference', 20)->default('immediately');
            $table->time('next_day_at')->nullable();
            $table->string('google_review_url', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_feedback_delivery_configs');
    }
};
