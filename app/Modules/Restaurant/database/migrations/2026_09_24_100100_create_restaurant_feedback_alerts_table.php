<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_feedback_alerts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->unsignedBigInteger('restaurant_feedback_request_id');
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamps();
            $table->unique('restaurant_feedback_request_id', 'rfa_request_unique');
            $table->index(['workspace_id', 'status'], 'rfa_workspace_status_idx');
            $table->foreign('workspace_id', 'rfa_workspace_fk')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('restaurant_feedback_request_id', 'rfa_request_fk')->references('id')->on('restaurant_feedback_requests')->cascadeOnDelete();
            $table->foreign('outlet_id', 'rfa_outlet_fk')->references('id')->on('restaurant_outlets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_feedback_alerts');
    }
};
