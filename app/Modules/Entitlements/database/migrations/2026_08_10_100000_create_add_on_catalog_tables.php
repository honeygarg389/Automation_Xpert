<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1, slice 1 — the add-on catalog. SCHEMA ONLY, wired to nothing.
 *
 * Nothing reads these tables yet. `plans.limits` remains authoritative and
 * untouched; the resolver that reads from here is a later slice, and the column
 * is not dropped until a test proves nothing reads it.
 *
 * ─── Not workspace-scoped, and it is worth saying why ───────────────────────
 *
 * None of these tables carries `workspace_id`, so none is customer data. That is
 * not an assumption — §A.5 of the Phase 0 plan classifies the entire
 * catalog/pricing layer (`Plan`, `Coupon`, `PaymentGatewayConfig`,
 * `BillingEvent`) as platform-global with no scope, and these are the same
 * layer. `entitlement_grants` is owned by a client or a partner, which matches
 * §A.3 (`ClientSubscription` is client-owned and likewise unscoped).
 *
 * ⚠️ The Phase 0 coverage guard CANNOT see any of this — it inventories tables
 * that HAVE a `workspace_id` column. So the classification is asserted in
 * `AddOnCatalogSchemaTest` instead, the same way `Partner` and `Client` are.
 *
 * ─── Deliberately absent ────────────────────────────────────────────────────
 *
 * No `commission_rate`, no `metadata` JSON, no proration columns, no
 * `trial_days`, no `partner_plan_id`. Each belongs to a phase that has not been
 * designed. Adding a column later is cheaper than removing one that shipped
 * carrying a guessed meaning — and a `metadata` JSON in particular becomes the
 * place every undesigned field goes to avoid a decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── What is sellable ────────────────────────────────────────────────
        Schema::create('add_ons', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('slug', 128)->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // ⚠️ THE COLUMN THE WHOLE DESIGN TURNS ON.
            //
            // CLAUDE.md rule 5: full software packages are NOT summed — the
            // highest eligible package wins; only explicit packs/credits are
            // additive. This column is that rule expressed as DATA rather than
            // as a branch in the resolver:
            //
            //   package  dominant — highest `rank` wins outright, never summed
            //   pack     additive — value * quantity, summed onto the package
            //   feature  boolean  — OR across everything held
            //
            // The resolver folds by this and nothing else. Selling something new
            // is a catalog row, not a code change.
            $table->string('type', 16);

            // Dominance order among `package` rows. Meaningless for the other
            // two types, which is why it defaults to 0 rather than being
            // required — a pack with a rank would imply a comparison that never
            // happens.
            $table->unsignedInteger('rank')->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index('rank');
        });

        // ── What an add-on confers ──────────────────────────────────────────
        Schema::create('add_on_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('add_on_id')->constrained('add_ons')->cascadeOnDelete();

            // Matches the existing plans.limits key exactly — `campaigns_per_month`,
            // `storage`, `chatbots`. Migrating the current limits must copy these
            // FAITHFULLY; see BUG-025, where renaming `storage` to `storage_gb`
            // in passing would change every customer's quota in both directions.
            $table->string('key', 64);

            // ⚠️ The distinction BUG-024 shows the current design lacks.
            //
            //   counter  per-period, measured by usage_meters ("100 campaigns
            //            this month"). Only ever increases within a period.
            //   gauge    cardinality, measured by COUNT(*) at request time
            //            ("5 chatbots at once"). Goes DOWN when one is deleted,
            //            which a counter can never do.
            //   boolean  a feature flag; `value` is ignored.
            //
            // Seven of the fourteen seeded limit keys are gauges, and one of
            // them (`knowledge_bases`) has middleware attached that asks a
            // counter how many exist — so it reads 0 forever and never bites.
            // Enforcement differs per kind, so the kind must be DECLARED, not
            // guessed from whether the key ends in `_per_month`.
            $table->string('kind', 16);

            // NULL means UNLIMITED, matching plans.limits' existing convention
            // so the migration of existing plans is a copy rather than a
            // translation. Unlimited dominates any number at every fold step.
            $table->unsignedBigInteger('value')->nullable();

            $table->timestamps();

            $table->unique(['add_on_id', 'key']);
        });

        // ── What it costs ───────────────────────────────────────────────────
        Schema::create('add_on_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('add_on_id')->constrained('add_ons')->cascadeOnDelete();

            // Deliberately mirrors the columns `plans` already carries rather
            // than inventing a second pricing vocabulary. If a gateway id lives
            // on `plans` under one name, it lives here under the same one.
            $table->string('currency_code', 10);
            $table->string('interval', 16); // month, year, one_time
            $table->unsignedBigInteger('price_cents');
            $table->string('stripe_price_id')->nullable();
            $table->string('paddle_price_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['add_on_id', 'currency_code', 'interval']);
        });

        // ── What a plan includes ────────────────────────────────────────────
        //
        // The backward-compatibility bridge, not a new concept. Each existing
        // plan gets one synthesized `package` add-on carrying its current
        // limits, and links to it here. Existing subscriptions need no migration
        // at all: they still point at plan_id, and the resolver walks
        // plan -> plan_add_on -> add_on_grants.
        Schema::create('plan_add_on', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('add_on_id')->constrained('add_ons')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['plan_id', 'add_on_id']);
        });

        // ── Who holds what ──────────────────────────────────────────────────
        Schema::create('entitlement_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // ⚠️ TWO NULLABLE FKs, NOT A POLYMORPHIC OWNER.
            //
            // A morph (`owner_type`/`owner_id`) cannot carry a foreign key, and
            // BUG-021 records that 121 of 154 FK-shaped columns in this schema
            // already lack one. New work should not add to that pile — the right
            // resolution there is to raise the other 121, not to lower these.
            //
            // Exactly one must be set. The database cannot express that portably
            // in a way Laravel's schema builder reaches, so it is enforced in the
            // model's saving hook and asserted by test, both directions.
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->cascadeOnDelete();

            $table->foreignId('add_on_id')->constrained('add_ons')->restrictOnDelete();

            // Only meaningful for `pack`: the resolver sums value * quantity.
            $table->unsignedInteger('quantity')->default(1);

            $table->string('status', 32)->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // plan | purchase | manual. `manual` is the one that needs an audit
            // trail: CLAUDE.md rule 9 requires permission + reason + dates + log
            // for an override, so the reason is stored beside the grant rather
            // than only in the audit log, where a later reader of THIS table
            // would not find it.
            $table->string('source', 16)->default('manual');
            $table->foreignId('assigned_by_admin_id')->nullable()
                ->constrained('admin_users')->nullOnDelete();
            $table->text('reason')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['partner_id', 'status']);
        });

        // ── R-1: the partner ceiling's tri-state ────────────────────────────
        Schema::table('partners', function (Blueprint $table) {
            // ⚠️ Rule 6 says a partner's entitlement is a CEILING, always. But
            // `partners` ships with no entitlements, so an empty grant set is
            // ambiguous in the most dangerous possible way:
            //
            //   "empty = grants nothing"      -> every partner's customers get
            //                                    zero of everything;
            //   "empty = no ceiling set"      -> rule 6 is silently not in
            //                                    force and the first partner
            //                                    onboarded resells unlimited.
            //
            // Neither is acceptable, and the second is the failure class this
            // codebase has just spent a phase eliminating — a limit resolving to
            // null, read as unlimited, with nothing anywhere saying so (BUG-023).
            //
            // So it is a recorded decision instead. Existing rows default to
            // `unrestricted`; moving to `ceiling` requires at least one grant,
            // enforced at write time in Partner::save().
            //
            // NO ->after(): naming a position forces a full table rebuild, where
            // appending last allows ALGORITHM=INSTANT.
            $table->string('entitlement_mode', 16)->default('unrestricted');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('entitlement_mode');
        });

        // Children before parents: entitlement_grants and plan_add_on both
        // reference add_ons.
        Schema::dropIfExists('entitlement_grants');
        Schema::dropIfExists('plan_add_on');
        Schema::dropIfExists('add_on_prices');
        Schema::dropIfExists('add_on_grants');
        Schema::dropIfExists('add_ons');
    }
};
