<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores deliberate local selections only. It is not a delivery queue or a
 * readiness cache: a future sender must re-evaluate the selected records and
 * RestaurantOutboundPolicy immediately before it sends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_digital_bill_delivery_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->index('workspace_id');
            $table->foreignId('outlet_id')->unique()->constrained('restaurant_outlets')->cascadeOnDelete();
            $table->unsignedBigInteger('whatsapp_phone_number_id')->nullable();
            $table->index('whatsapp_phone_number_id', 'rdbdc_phone_idx');
            $table->foreign('whatsapp_phone_number_id', 'rdbdc_phone_fk')
                ->references('id')->on('whatsapp_phone_numbers')->nullOnDelete();
            $table->unsignedBigInteger('whatsapp_template_id')->nullable();
            $table->index('whatsapp_template_id', 'rdbdc_template_idx');
            $table->foreign('whatsapp_template_id', 'rdbdc_template_fk')
                ->references('id')->on('whatsapp_templates')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_digital_bill_delivery_configs', function (Blueprint $table): void {
            $table->dropForeign('rdbdc_phone_fk');
            $table->dropIndex('rdbdc_phone_idx');
            $table->dropForeign('rdbdc_template_fk');
            $table->dropIndex('rdbdc_template_idx');
        });

        Schema::dropIfExists('restaurant_digital_bill_delivery_configs');
    }
};
