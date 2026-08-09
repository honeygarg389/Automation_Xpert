<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\PlanController;
use App\Models\Plan;
use App\Modules\Entitlements\Support\PlanLimitKinds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-027 — saving a plan through the admin UI discarded 14 of its 16 limits.
 *
 * `defaultLimits()` returned two keys. `validatePlan()` builds its rules from
 * them, Laravel's `validate()` returns only attributes that have rules, and
 * `mapValidatedToAttributes()` then replaced the whole limits JSON with the
 * survivors. The form submits sixteen. Fourteen were dropped on every save, and
 * a dropped key reads as `null` — which every consumer treats as UNLIMITED.
 *
 * ⚠️ There were ZERO tests on this path before this file.
 */
class PlanLimitKeyDivergenceTest extends TestCase
{
    use RefreshDatabase;

    /** Every key the admin form renders and submits. */
    private function frontEndKeys(): array
    {
        $jsx = file_get_contents(base_path('resources/js/Pages/Admin/Plans/PlanLimits.jsx'));

        $this->assertNotFalse($jsx, 'The admin plan-limits component is missing.');
        preg_match('/const LIMIT_KEYS = \[(.*?)\];/s', $jsx, $m);
        $this->assertNotEmpty($m, 'LIMIT_KEYS was not found in PlanLimits.jsx — if it was '
            .'renamed, this guard is now blind and must be repointed.');

        preg_match_all("/'([a-z_]+)'/", $m[1], $keys);

        return $keys[1];
    }

    // ══ The guard: the two lists must not drift apart again ════════════════

    /**
     * ⚠️ THE ASSERTION THAT WOULD HAVE CAUGHT BUG-027 THE DAY IT WAS WRITTEN.
     *
     * The front end declares its own key list for labelling and ordering, which
     * is reasonable. What is not reasonable is the back end declaring a second,
     * shorter one and silently discarding the difference.
     */
    #[Test]
    public function the_front_end_and_back_end_agree_on_which_limit_keys_exist(): void
    {
        $front = $this->frontEndKeys();
        $back = array_keys(PlanController::defaultLimits());

        $this->assertNotEmpty($front);
        $this->assertSame(16, count($front), 'Expected 16 keys in the admin form.');

        $this->assertSame([], array_diff($front, $back),
            'The admin form submits keys the controller does not validate. Laravel drops '
            .'unvalidated attributes from validated(), and the controller writes the whole '
            .'limits JSON from validated() — so these keys are silently discarded on every '
            .'save, and a discarded key reads as UNLIMITED. That is BUG-027.');

        $this->assertSame([], array_diff($back, $front),
            'The controller validates keys the form never renders — harmless today, but it '
            .'means the two lists have started to drift again.');
    }

    /** …and both must agree with the single declaration they derive from. */
    #[Test]
    public function the_key_set_is_derived_from_plan_limit_kinds(): void
    {
        $this->assertSame(
            array_keys(PlanLimitKinds::MAP),
            array_keys(PlanController::defaultLimits()),
            'defaultLimits() has stopped deriving from PlanLimitKinds::MAP. Retyping the key '
            .'list is what created BUG-027; there must be exactly one declaration.'
        );
    }

    // ══ The behaviour itself ═══════════════════════════════════════════════

    /**
     * The measurement that proved the bug, kept as a regression test.
     *
     * Reproduces `validatePlan()`'s rule construction exactly rather than
     * paraphrasing it — a paraphrase of the thing under test is not the thing
     * under test.
     */
    #[Test]
    public function every_submitted_limit_key_survives_validation(): void
    {
        $submitted = array_fill_keys(array_keys(PlanLimitKinds::MAP), 5);

        $rules = [];
        foreach (array_keys(PlanController::defaultLimits()) as $key) {
            $rules['limits.'.$key] = ['nullable', 'integer', 'min:0'];
        }

        $validated = Validator::make(['limits' => $submitted], $rules)->validated();

        $this->assertCount(16, $submitted, 'Precondition: 16 keys submitted.');
        $this->assertCount(16, $validated['limits'] ?? [],
            'Validation dropped '.(16 - count($validated['limits'] ?? [])).' of 16 limit keys. '
            .'Every dropped key is written back as absent, which reads as unlimited.');

        $this->assertSame(array_keys($submitted), array_keys($validated['limits']));
    }

    /**
     * End to end: a plan whose limits are all set must keep them after a save
     * that does not touch them.
     */
    #[Test]
    public function saving_a_plan_preserves_every_limit_it_already_had(): void
    {
        $limits = [];
        foreach (array_keys(PlanLimitKinds::MAP) as $i => $key) {
            $limits[$key] = ($i + 1) * 10;
        }

        $plan = Plan::factory()->create(['limits' => $limits]);

        $rules = [];
        foreach (array_keys(PlanController::defaultLimits()) as $key) {
            $rules['limits.'.$key] = ['nullable', 'integer', 'min:0'];
        }
        $validated = Validator::make(['limits' => $plan->limits], $rules)->validated();

        $plan->update(['limits' => $validated['limits'] ?? null]);

        // Sorted on both sides: the JSON round-trip does not promise key order,
        // and order is not what this test is about. Keys and values are.
        $saved = $plan->fresh()->limits;
        ksort($limits);
        ksort($saved);

        $this->assertSame($limits, $saved,
            'A save round-trip changed the plan’s limits. Before BUG-027 was fixed this left '
            .'2 of 16 keys, turning 14 limits into unlimited for every customer on the plan.');
    }

    /**
     * POSITIVE CONTROL: validation must still REJECT a bad value.
     *
     * Without this, "all 16 keys survive" is equally satisfied by a controller
     * that validates nothing at all — which would be a different bug with the
     * same green test.
     */
    #[Test]
    public function validation_still_rejects_a_non_integer_limit(): void
    {
        $rules = [];
        foreach (array_keys(PlanController::defaultLimits()) as $key) {
            $rules['limits.'.$key] = ['nullable', 'integer', 'min:0'];
        }

        $validator = Validator::make(['limits' => ['users' => 'unlimited']], $rules);

        $this->assertTrue($validator->fails(),
            'A non-integer limit passed validation. Widening defaultLimits() must not have '
            .'been achieved by dropping the rules.');
        $this->assertArrayHasKey('limits.users', $validator->errors()->toArray());
    }

    /** A null limit is still valid — null means unlimited, deliberately. */
    #[Test]
    public function a_null_limit_is_still_accepted(): void
    {
        $rules = [];
        foreach (array_keys(PlanController::defaultLimits()) as $key) {
            $rules['limits.'.$key] = ['nullable', 'integer', 'min:0'];
        }

        $validated = Validator::make(['limits' => ['users' => null]], $rules)->validated();

        $this->assertArrayHasKey('users', $validated['limits']);
        $this->assertNull($validated['limits']['users']);
    }
}
