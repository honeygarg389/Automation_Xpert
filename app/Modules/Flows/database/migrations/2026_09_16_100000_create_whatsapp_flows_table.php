<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_flows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->string('category', 32)->nullable();

            // This is the platform author's lifecycle, not Meta's API status.
            $table->string('status', 20)->default('draft');
            $table->json('screens');
            $table->json('submit_settings')->nullable();

            // Reserved for the later Graph API create/upload/publish slice.
            $table->string('meta_flow_id', 64)->nullable();
            $table->string('meta_sync_status', 20)->nullable();
            $table->json('meta_validation_errors')->nullable();
            $table->text('meta_sync_error')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('workspace_id');
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flows');
    }
};
