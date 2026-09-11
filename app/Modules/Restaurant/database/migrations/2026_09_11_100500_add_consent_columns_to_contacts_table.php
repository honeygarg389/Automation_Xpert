<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant foundation — narrowed WhatsApp consent tracking, and a SEPARATE
 * digital-bill opt-out.
 *
 * `whatsapp_consent_purpose` is intentionally narrow: its PHP const scopes it
 * to marketing/profile-building values ONLY (see Contact::WHATSAPP_CONSENT_PURPOSES).
 * It must never be read as gating transactional or feedback messages — those
 * are not consent-gated the same way, and conflating the two would either
 * over-block operational messages or launder marketing consent into cover
 * for them.
 *
 * `whatsapp_opted_out_at` / `whatsapp_opt_out_source` are the broad STOP-
 * keyword suppression — opting out here silences WhatsApp messaging overall.
 *
 * `digital_bill_opted_out_at` / `digital_bill_opt_out_source` are a
 * SEPARATE, NARROWER opt-out — e-bill delivery only. A contact who opts out
 * of digital bills has said nothing about marketing or general WhatsApp
 * consent, and vice versa; these must not be conflated into one flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('whatsapp_consent_at')->nullable();
            $table->string('whatsapp_consent_source', 64)->nullable();
            $table->string('whatsapp_consent_purpose', 32)->nullable();
            $table->string('whatsapp_consent_text_version', 32)->nullable();
            $table->json('whatsapp_consent_evidence')->nullable();

            $table->timestamp('whatsapp_opted_out_at')->nullable();
            $table->string('whatsapp_opt_out_source', 64)->nullable();

            $table->timestamp('digital_bill_opted_out_at')->nullable();
            $table->string('digital_bill_opt_out_source', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_consent_at',
                'whatsapp_consent_source',
                'whatsapp_consent_purpose',
                'whatsapp_consent_text_version',
                'whatsapp_consent_evidence',
                'whatsapp_opted_out_at',
                'whatsapp_opt_out_source',
                'digital_bill_opted_out_at',
                'digital_bill_opt_out_source',
            ]);
        });
    }
};
