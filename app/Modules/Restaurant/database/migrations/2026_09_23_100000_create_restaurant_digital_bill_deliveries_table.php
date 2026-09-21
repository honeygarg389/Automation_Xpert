<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_digital_bill_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->index('workspace_id', 'rdbd_workspace_idx');
            $table->foreign('workspace_id', 'rdbd_workspace_fk')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->unsignedBigInteger('restaurant_bill_id');
            $table->index('restaurant_bill_id', 'rdbd_bill_idx');
            $table->foreign('restaurant_bill_id', 'rdbd_bill_fk')->references('id')->on('restaurant_bills')->cascadeOnDelete();
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->index('outlet_id', 'rdbd_outlet_idx');
            $table->foreign('outlet_id', 'rdbd_outlet_fk')->references('id')->on('restaurant_outlets')->nullOnDelete();
            $table->unsignedBigInteger('digital_bill_delivery_config_id')->nullable();
            $table->index('digital_bill_delivery_config_id', 'rdbd_config_idx');
            $table->foreign('digital_bill_delivery_config_id', 'rdbd_config_fk')->references('id')->on('restaurant_digital_bill_delivery_configs')->nullOnDelete();
            $table->string('purpose', 32);
            $table->string('status', 20);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('provider_attempt_started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('suppressed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('provider_message_id', 255)->nullable();
            $table->string('reason_code', 100)->nullable();
            $table->timestamps();
            $table->unique(['restaurant_bill_id', 'purpose'], 'rdbd_bill_purpose_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_digital_bill_deliveries');
    }
};
