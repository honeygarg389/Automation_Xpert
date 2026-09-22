<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_feedback_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->index('workspace_id', 'rfr_workspace_idx');
            $table->foreign('workspace_id', 'rfr_workspace_fk')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('restaurant_bill_id');
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->unsignedBigInteger('restaurant_feedback_delivery_config_id')->nullable();
            $table->string('purpose', 32);
            $table->char('public_token', 64)->unique('rfr_public_token_unique');
            $table->timestamp('scheduled_for')->nullable();
            $table->string('status', 24);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('provider_attempt_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->string('reason_code', 96)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('customer_comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('manager_alerted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_bill_id', 'purpose'], 'rfr_bill_purpose_unique');
            $table->index(['status', 'scheduled_for'], 'rfr_due_idx');
            $table->foreign('restaurant_bill_id', 'rfr_bill_fk')->references('id')->on('restaurant_bills')->cascadeOnDelete();
            $table->foreign('outlet_id', 'rfr_outlet_fk')->references('id')->on('restaurant_outlets')->nullOnDelete();
            $table->foreign('restaurant_feedback_delivery_config_id', 'rfr_config_fk')->references('id')->on('restaurant_feedback_delivery_configs')->nullOnDelete();
        });

        DB::statement('ALTER TABLE restaurant_feedback_requests ADD CONSTRAINT rfr_rating_check CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_feedback_requests');
    }
};
