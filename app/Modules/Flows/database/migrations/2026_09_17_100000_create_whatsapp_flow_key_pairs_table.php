<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_flow_key_pairs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // Meta registers encryption independently for each phone-number ID.
            $table->foreignId('whatsapp_phone_number_id')->constrained('whatsapp_phone_numbers')->cascadeOnDelete();
            $table->text('public_key_pem');
            $table->text('private_key_pem');
            $table->unsignedInteger('key_version')->default(1);
            $table->string('meta_upload_status', 20)->default('not_uploaded');
            $table->timestamp('meta_uploaded_at')->nullable();
            $table->string('meta_upload_error', 512)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->index('workspace_id');
            $table->unique(['whatsapp_phone_number_id', 'key_version'], 'flow_key_pairs_phone_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flow_key_pairs');
    }
};
