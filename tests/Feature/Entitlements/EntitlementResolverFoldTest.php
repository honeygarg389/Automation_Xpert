<?php

namespace Tests\Feature\Entitlements;

use App\Modules\Entitlements\Models\AddOn;
use App\Modules\Entitlements\Services\EntitlementResolver;
use App\Modules\Entitlements\Support\Entitlement;
use App\Modules\Entitlements\Support\GrantBundle;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ THE HALF OF THE CANARY THE PLAN-BASED TEST STRUCTURALLY CANNOT PROVE.
 *
 * `EntitlementResolverCanaryTest` folds ONE bundle per workspace, because every
 * seeded plan synthesizes exactly one package and a customer holds one plan. A
 * single bundle folds identically whether packages are dominant or summed — so
 * breaking the dominant rule leaves that entire file green. Measured, not
 * assumed.
 *
 * Every test here therefore constructs MULTIPLE bundles, and each one is written
 * against the question "what would a WRONG fold produce?". If a wrong fold
 * produces the same number, the assertion is decoration.
 *
 * The values are chosen so the three candidate answers are always distinct:
 *
 *   packages 100 and 250, ranks 10 and 20
 *     dominant (correct) -> 250
 *     summed   (wrong)   -> 350
 *     first-wins (wrong) -> 100
 *
 * No database. The fold is pure, and giving it a database would only add ways
 * for the test to pass for reasons unrelated to the fold.
 */
class EntitlementResolverFoldTest extends TestCase
{
    private function fold(GrantBundle ...$bundles): Entitlement
    {
        return (new EntitlementResolver)->fold($bundles);
    }

    private function package(int $rank, array $grants): GrantBundle
    {
        return new GrantBundle(AddOn::TYPE_PACKAGE, rank: $rank, grants: $grants);
    }

    private function pack(array $grants, int $quantity = 1): GrantBundle
    {
        return new GrantBundle(AddOn::TYPE_PACK, quantity: $quantity, grants: $grants);
    }

    private function feature(string ...$keys): GrantBundle
    {
        return new GrantBundle(AddOn::TYPE_FEATURE, grants: array_fill_keys($keys, null));
    }

    // ══ DOMINANT — packages are never summed ═══════════════════════════════

    /**
     * ⚠️ THE RULE MOST LIKELY TO BE GOT WRONG, and the one the plan-based canary
     * cannot see.
     *
     * 100 and 250 at ranks 10 and 20. Correct is 250. A summing fold gives 350,
     * a first-wins fold gives 100. All three differ, so exactly one passes.
     */
    #[Test]
    public function the_highest_ranked_package_wins_outright_and_is_never_summed(): void
    {
        $e = $this->fold(
            $this->package(10, ['campaigns_per_month' => 100]),
            $this->package(20, ['campaigns_per_month' => 250]),
        );

        $this->assertSame(250, $e->limit('campaigns_per_month'),
            'Expected the rank-20 package to win outright. 350 means packages were summed '
            .'(CLAUDE.md rule 5 broken); 100 means the first was kept rather than the highest.');
    }

    /** Order of arrival must not decide it — same case, reversed input. */
    #[Test]
    public function package_dominance_does_not_depend_on_input_order(): void
    {
        $e = $this->fold(
            $this->package(20, ['campaigns_per_month' => 250]),
            $this->package(10, ['campaigns_per_month' => 100]),
        );

        $this->assertSame(250, $e->limit('campaigns_per_month'));
    }

    /**
     * The losing package is discarded ENTIRELY — including keys the winner never
     * mentioned.
     *
     * This is the subtle half of "dominant". A fold that merges the loser's
     * extra keys in still looks correct on the shared key, and quietly grants a
     * customer something their actual package does not include.
     */
    #[Test]
    public function the_losing_package_contributes_nothing_at_all(): void
    {
        $e = $this->fold(
            $this->package(10, ['campaigns_per_month' => 100, 'chatbots' => 5]),
            $this->package(20, ['campaigns_per_month' => 250]),
        );

        $this->assertSame(250, $e->limit('campaigns_per_month'));
        $this->assertFalse($e->has('chatbots'),
            'The losing package’s chatbots limit survived. A dominant package REPLACES, it does '
            .'not merge — otherwise a customer keeps entitlements from a package they left.');
    }

    // ══ ADDITIVE — packs stack ═════════════════════════════════════════════

    #[Test]
    public function packs_are_added_onto_the_winning_package(): void
    {
        $e = $this->fold(
            $this->package(10, ['campaigns_per_month' => 100]),
            $this->pack(['campaigns_per_month' => 50]),
        );

        $this->assertSame(150, $e->limit('campaigns_per_month'),
            '100 means the pack was ignored; 50 means it replaced the package.');
    }

    #[Test]
    public function a_packs_quantity_multiplies_its_value(): void
    {
        $e = $this->fold(
            $this->package(10, ['campaigns_per_month' => 100]),
            $this->pack(['campaigns_per_month' => 50], quantity: 3),
        );

        $this->assertSame(250, $e->limit('campaigns_per_month'),
            '150 means quantity was ignored — the customer bought three and got one.');
    }

    #[Test]
    public function several_packs_all_stack(): void
    {
        $e = $this->fold(
            $this->package(10, ['campaigns_per_month' => 100]),
            $this->pack(['campaigns_per_month' => 50]),
            $this->pack(['campaigns_per_month' => 25], quantity: 2),
        );

        $this->assertSame(200, $e->limit('campaigns_per_month'));
    }

    /**
     * A pack may grant a key no package mentions — and it must land as its own
     * value, not be folded into a base that does not exist.
     *
     * The wrong fold here treats "absent" as an unlimited base and returns null,
     * which reads as unlimited: a 50-message top-up becoming infinite messages.
     */
    #[Test]
    public function a_pack_granting_an_unmentioned_key_lands_as_its_own_value(): void
    {
        $e = $this->fold(
            $this->package(10, ['campaigns_per_month' => 100]),
            $this->pack(['lead_credits_per_month' => 500]),
        );

        $this->assertSame(500, $e->limit('lead_credits_per_month'),
            'null here would read as UNLIMITED to every consumer — a bounded top-up becoming '
            .'infinite.');
        $this->assertTrue($e->has('lead_credits_per_month'));
    }

    /** A pack with no package at all still grants. */
    #[Test]
    public function a_pack_without_any_package_still_grants_its_value(): void
    {
        $e = $this->fold($this->pack(['campaigns_per_month' => 40]));

        $this->assertSame(40, $e->limit('campaigns_per_month'));
    }

    // ══ NULL DOMINATES — in both directions ════════════════════════════════

    /**
     * ⚠️ An unlimited package cannot be made finite by adding a pack to it.
     *
     * The wrong fold does `(null ?? 0) + 50` and returns 50 — converting an
     * enterprise customer's unlimited campaigns into fifty. That is a downgrade
     * caused by a purchase.
     */
    #[Test]
    public function an_unlimited_package_stays_unlimited_when_a_pack_is_added(): void
    {
        $e = $this->fold(
            $this->package(10, ['campaigns_per_month' => null]),
            $this->pack(['campaigns_per_month' => 50]),
        );

        $this->assertNull($e->limit('campaigns_per_month'),
            'Adding a 50-campaign pack to an UNLIMITED plan produced a finite limit. The '
            .'customer bought more and received less.');
        $this->assertTrue($e->isUnlimited('campaigns_per_month'));
    }

    /** …and an unlimited PACK makes a finite package unlimited. */
    #[Test]
    public function an_unlimited_pack_makes_a_finite_package_unlimited(): void
    {
        $e = $this->fold(
            $this->package(10, ['campaigns_per_month' => 100]),
            $this->pack(['campaigns_per_month' => null]),
        );

        $this->assertNull($e->limit('campaigns_per_month'));
        $this->assertTrue($e->isUnlimited('campaigns_per_month'));
    }

    /** Unlimited survives however many packs follow it. */
    #[Test]
    public function unlimited_survives_repeated_additions(): void
    {
        $e = $this->fold(
            $this->package(10, ['campaigns_per_month' => null]),
            $this->pack(['campaigns_per_month' => 50]),
            $this->pack(['campaigns_per_month' => 500], quantity: 4),
        );

        $this->assertNull($e->limit('campaigns_per_month'));
    }

    /**
     * ⚠️ Unlimited is a GRANT, and must not be confused with absence.
     *
     * Both make `limit()` return null. If the fold cannot tell them apart, an
     * ungranted key looks exactly like an unlimited one — which is BUG-023 in
     * one line.
     */
    #[Test]
    public function unlimited_and_absent_both_read_null_but_are_distinguishable(): void
    {
        $e = $this->fold($this->package(10, ['campaigns_per_month' => null]));

        $this->assertNull($e->limit('campaigns_per_month'));
        $this->assertNull($e->limit('never_granted'));

        $this->assertTrue($e->has('campaigns_per_month'));
        $this->assertFalse($e->has('never_granted'));

        $this->assertTrue($e->isUnlimited('campaigns_per_month'));
        $this->assertFalse($e->isUnlimited('never_granted'),
            'An ungranted key reported itself as unlimited — absence is not a grant.');
    }

    // ══ BOOLEAN — OR ═══════════════════════════════════════════════════════

    #[Test]
    public function features_or_together_and_default_to_false(): void
    {
        $e = $this->fold(
            $this->feature('white_label'),
            $this->feature('api_access'),
        );

        $this->assertTrue($e->allows('white_label'));
        $this->assertTrue($e->allows('api_access'));
        $this->assertFalse($e->allows('never_granted'),
            'An ungranted feature must default to false, not to true.');
    }

    /** Holding a feature twice is still just true. */
    #[Test]
    public function holding_a_feature_twice_is_still_true(): void
    {
        $e = $this->fold($this->feature('white_label'), $this->feature('white_label'));

        $this->assertTrue($e->allows('white_label'));
        $this->assertSame(['white_label' => true], $e->flags());
    }

    /** Features and limits occupy separate spaces and must not collide. */
    #[Test]
    public function a_feature_does_not_leak_into_the_numeric_limits(): void
    {
        $e = $this->fold(
            $this->package(10, ['campaigns_per_month' => 100]),
            $this->feature('white_label'),
        );

        $this->assertSame(['campaigns_per_month' => 100], $e->limits());
        $this->assertNull($e->limit('white_label'),
            'A boolean feature appeared among the numeric limits, where a consumer would read '
            .'its null as "unlimited".');
    }

    // ══ Everything at once ═════════════════════════════════════════════════

    /**
     * The full fold, with every rule active and every wrong answer distinct.
     *
     *   campaigns   pkg20=250 (pkg10=100 discarded) + 50 + 25x2 = 350
     *   chatbots    unlimited on the winner, pack must not bound it   = null
     *   leads       granted only by a pack                            = 500
     *   white_label feature                                           = true
     */
    #[Test]
    public function the_whole_fold_composes(): void
    {
        $e = $this->fold(
            $this->package(10, ['campaigns_per_month' => 100, 'inbox_agents' => 99]),
            $this->package(20, ['campaigns_per_month' => 250, 'chatbots' => null]),
            $this->pack(['campaigns_per_month' => 50]),
            $this->pack(['campaigns_per_month' => 25], quantity: 2),
            $this->pack(['chatbots' => 3]),
            $this->pack(['lead_credits_per_month' => 500]),
            $this->feature('white_label'),
        );

        $this->assertSame(350, $e->limit('campaigns_per_month'));
        $this->assertNull($e->limit('chatbots'));
        $this->assertTrue($e->isUnlimited('chatbots'));
        $this->assertSame(500, $e->limit('lead_credits_per_month'));
        $this->assertTrue($e->allows('white_label'));

        $this->assertFalse($e->has('inbox_agents'),
            'inbox_agents came from the LOSING package and must not survive.');
    }

    #[Test]
    public function an_empty_fold_grants_nothing(): void
    {
        $e = $this->fold();

        $this->assertSame([], $e->limits());
        $this->assertSame([], $e->flags());
    }

    /** An unknown type refuses rather than being silently dropped. */
    #[Test]
    public function an_unknown_bundle_type_is_refused(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/Unknown add-on type/');

        $this->fold(new GrantBundle('subscription', grants: ['campaigns_per_month' => 10]));
    }
}
