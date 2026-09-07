<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quarterly and half-yearly pricing on `plans`.
 *
 * Types mirror their existing siblings exactly: prices are
 * `bigint unsigned NULL` like monthly_price_cents/yearly_price_cents, price ids
 * are `varchar(255) NULL` like stripe_monthly_id/stripe_yearly_id.
 *
 * ⚠️ NULLABLE WITH NO DEFAULT, deliberately. A null price means "this plan is
 * not sold on this cycle" — `Plan::priceCentsForCycle()` returns null and every
 * gateway refuses with "no price for this billing cycle". A default of 0 would
 * mean "free", which is a different and much worse claim.
 *
 * Purely additive: no existing column is touched, and every existing plan
 * continues to offer exactly the cycles it offered before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedBigInteger('quarterly_price_cents')->nullable()->after('monthly_price_cents');
            $table->unsignedBigInteger('half_yearly_price_cents')->nullable()->after('quarterly_price_cents');
            $table->string('stripe_quarterly_id')->nullable()->after('stripe_monthly_id');
            $table->string('stripe_half_yearly_id')->nullable()->after('stripe_quarterly_id');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'quarterly_price_cents',
                'half_yearly_price_cents',
                'stripe_quarterly_id',
                'stripe_half_yearly_id',
            ]);
        });
    }
};
