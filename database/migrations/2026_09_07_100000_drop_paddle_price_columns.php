<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop Paddle's per-plan price id columns, with the Paddle gateway itself.
 *
 * ⚠️ STRIPE'S EQUIVALENT COLUMNS ARE DELIBERATELY UNTOUCHED. `stripe_monthly_id`
 * and `stripe_yearly_id` are live: StripeGateway::createCheckout() PREFERS them
 * over the local price when set, and changePlan() REQUIRES them. Dropping them
 * would break in-place plan changes outright.
 *
 * These four are safe to drop because the only reader of each was deleted with
 * its gateway. Paddle was the sole consumer of the plan columns, and
 * `add_on_prices.paddle_price_id` never had a reader, a writer or factory
 * support at all — Phase 1 copied the two-gateway-column shape from `plans`
 * before either was reconsidered.
 *
 * ⚠️ down() RESTORES THE COLUMNS BUT NOT THEIR CONTENTS. That is honest rather
 * than lossy-by-accident: the values were Paddle catalog price ids, and once the
 * gateway is gone there is nothing they could address. Verified empty on the
 * working database before writing this (all plans NULL, add_on_prices had zero
 * rows), so this migration drops no data here — but check before running it
 * anywhere that has been configured with Paddle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['paddle_monthly_id', 'paddle_yearly_id']);
        });

        if (Schema::hasColumn('add_on_prices', 'paddle_price_id')) {
            Schema::table('add_on_prices', function (Blueprint $table) {
                $table->dropColumn('paddle_price_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('paddle_monthly_id')->nullable()->after('stripe_yearly_id');
            $table->string('paddle_yearly_id')->nullable()->after('paddle_monthly_id');
        });

        if (! Schema::hasColumn('add_on_prices', 'paddle_price_id')) {
            Schema::table('add_on_prices', function (Blueprint $table) {
                $table->string('paddle_price_id')->nullable()->after('stripe_price_id');
            });
        }
    }
};
