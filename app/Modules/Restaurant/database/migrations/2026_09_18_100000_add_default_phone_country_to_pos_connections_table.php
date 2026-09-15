<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A Slice 1 — an explicit, per-connection default phone country.
 *
 * Both attached Petpooja documents (Global API, Get Orders) show customer
 * phone numbers as bare local digits with no country code in every sample.
 * Nothing upstream of this column knows which country a given outlet's local
 * numbers belong to, and Petpooja operating primarily in India is not
 * evidence any specific outlet is Indian.
 *
 * Nullable, no default value — NOT even 'IN'. A connection with this unset
 * must never have a future ingestion step guess or coerce a country; see
 * PosConnectionProvisioningService's activation/creation docblocks and
 * StorePosConnectionRequest for where this is enforced. The 2-character
 * width matches App\Support\PhoneNumber::COUNTRIES' ISO 3166-1 alpha-2 keys
 * exactly — this column stores that same code, not a duplicate country
 * dataset (PhoneNumber::COUNTRIES/options() remains the single source of
 * truth for names, calling codes and validation).
 *
 * This slice does not read this column anywhere — no contact is created or
 * normalized from a Petpooja payload yet (that is Slice 3). It exists now so
 * the choice is captured, deliberately, at connection setup time, ahead of
 * the ingestion work that will need it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_connections', function (Blueprint $table) {
            $table->string('default_phone_country', 2)->nullable()->after('environment');
        });
    }

    public function down(): void
    {
        Schema::table('pos_connections', function (Blueprint $table) {
            $table->dropColumn('default_phone_country');
        });
    }
};
