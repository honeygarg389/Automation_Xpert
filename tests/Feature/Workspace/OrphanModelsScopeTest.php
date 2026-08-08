<?php

namespace Tests\Feature\Workspace;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\OnboardingStep;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\OnboardingService;
use App\Support\WorkspaceContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0, slice 9 — the two orphans.
 *
 * These are the only two models in the phase that needed a MIGRATION rather than
 * a trait: `onboarding_steps` and `webhook_endpoints` were customer-owned data
 * keyed to a user, with no workspace column at all.
 */
class OrphanModelsScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    // ══ BUG-009 — the behaviour this closes ═══════════════════════════════

    /**
     * ⚠️ THE NAMED BUG-009 TEST.
     *
     * Onboarding progress was RECORDED per user (`updateOrCreate(['user_id',
     * 'step'])`) while `getProgress()` DETECTED it per workspace. So a user who
     * finished a step in workspace A opened workspace B and found it already
     * ticked — for a step that workspace had never done.
     *
     * The discriminator is deliberately NOT "a row exists": a row existed before
     * the fix too. It is whether workspace B reads the step as INCOMPLETE.
     */
    #[Test]
    public function a_step_completed_in_one_workspace_does_not_read_as_complete_in_another(): void
    {
        ['user' => $user, 'workspace' => $a] = $this->createWorkspaceContext();
        ['workspace' => $b] = $this->createWorkspaceContext();

        $service = app(OnboardingService::class);

        $this->assertTrue($service->markStep($user, (int) $a->id, 'connect_first_channel', verify: false));

        $inA = collect($service->getProgress($user, (int) $a->id)['steps'] ?? []);
        $inB = collect($service->getProgress($user, (int) $b->id)['steps'] ?? []);

        $stepInA = $inA->firstWhere('key', 'connect_first_channel');
        $stepInB = $inB->firstWhere('key', 'connect_first_channel');

        $this->assertTrue((bool) ($stepInA['completed'] ?? false),
            'The step is not complete in the workspace it was completed in.');

        $this->assertFalse((bool) ($stepInB['completed'] ?? false),
            'BUG-009: a step completed in workspace A reads as complete in workspace B. '
            .'Progress is recorded per user but detected per workspace.');
    }

    /**
     * The other half, which the widened UNIQUE (user_id, workspace_id, step)
     * makes possible: the SAME user completing the SAME step in a second
     * workspace.
     *
     * Under the old UNIQUE (user_id, step) this was a duplicate-key error — so
     * fixing the record key without widening the index would have replaced a
     * silent wrong answer with a hard failure.
     */
    #[Test]
    public function the_same_user_can_complete_the_same_step_in_two_workspaces(): void
    {
        ['user' => $user, 'workspace' => $a] = $this->createWorkspaceContext();
        ['workspace' => $b] = $this->createWorkspaceContext();

        $service = app(OnboardingService::class);

        $service->markStep($user, (int) $a->id, 'connect_first_channel', verify: false);
        $service->markStep($user, (int) $b->id, 'connect_first_channel', verify: false);

        $this->assertSame(2, DB::table('onboarding_steps')
            ->where('user_id', $user->id)->where('step', 'connect_first_channel')->count(),
            'Two workspaces must each carry their own completion row.');
    }

    /** The widened index is real: the OLD key would have rejected the second row. */
    #[Test]
    public function the_unique_index_is_now_per_workspace(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM onboarding_steps'))
            ->filter(fn ($i) => ! $i->Non_unique && $i->Key_name !== 'PRIMARY')
            ->groupBy('Key_name')
            ->map(fn ($g) => $g->pluck('Column_name')->all());

        $this->assertSame(
            ['user_id', 'workspace_id', 'step'],
            $indexes['onboarding_steps_user_workspace_step_unique'] ?? [],
            'The unique index must include workspace_id, or the same step in a second '
            .'workspace collides on the old key.'
        );
        $this->assertArrayNotHasKey('onboarding_steps_user_id_step_unique', $indexes->all(),
            'The old per-user unique is still present and will reject the second workspace.');
    }

    /** Duplicate within ONE workspace is still refused — the constraint still constrains. */
    #[Test]
    public function a_duplicate_step_within_one_workspace_is_still_refused(): void
    {
        ['user' => $user, 'workspace' => $a] = $this->createWorkspaceContext();

        DB::table('onboarding_steps')->insert([
            'user_id' => $user->id, 'workspace_id' => $a->id, 'step' => 'connect_first_channel',
            'completed' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('onboarding_steps')->insert([
            'user_id' => $user->id, 'workspace_id' => $a->id, 'step' => 'connect_first_channel',
            'completed' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ══ The scope itself ═══════════════════════════════════════════════════

    #[Test]
    public function onboarding_step_is_scoped_and_filters(): void
    {
        $this->assertContains(BelongsToWorkspace::class, class_uses_recursive(OnboardingStep::class));

        ['user' => $user, 'workspace' => $a] = $this->createWorkspaceContext();
        ['workspace' => $b] = $this->createWorkspaceContext();

        foreach ([$a->id, $b->id] as $ws) {
            DB::table('onboarding_steps')->insert([
                'user_id' => $user->id, 'workspace_id' => $ws, 'step' => 'connect_first_channel',
                'completed' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, DB::table('onboarding_steps')->count(), 'Positive control.');
        $this->assertSame(1, WorkspaceContext::for((int) $a->id, fn () => OnboardingStep::count()));
        $this->assertSame(0, OnboardingStep::count(), 'Null context fails closed.');
    }

    #[Test]
    public function webhook_endpoint_is_scoped_and_filters(): void
    {
        $this->assertContains(BelongsToWorkspace::class, class_uses_recursive(WebhookEndpoint::class));

        ['user' => $user, 'workspace' => $a] = $this->createWorkspaceContext();
        ['user' => $other, 'workspace' => $b] = $this->createWorkspaceContext();

        WebhookEndpoint::factory()->create(['user_id' => $user->id, 'workspace_id' => $a->id]);
        WebhookEndpoint::factory()->create(['user_id' => $other->id, 'workspace_id' => $b->id]);

        $this->assertSame(2, DB::table('webhook_endpoints')->count(), 'Positive control.');
        $this->assertSame(1, WorkspaceContext::for((int) $a->id, fn () => WebhookEndpoint::count()));
        $this->assertSame(0, WebhookEndpoint::count());
    }

    /**
     * The migration made `workspace_id` NOT NULL, which turned "a user with no
     * workspace" from a silent null into an integrity-constraint 500. Both
     * writers now refuse cleanly instead.
     */
    #[Test]
    public function a_user_without_a_workspace_is_refused_rather_than_500ing(): void
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now(), 'workspace_id' => null]);

        $this->actingAs($user)
            ->post(route('client.webhooks.store'), ['url' => 'https://example.com/hook', 'events' => ['contact.created']])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('webhook_endpoints')->count());
    }

    /** markStep() likewise refuses rather than writing a null. */
    #[Test]
    public function mark_step_refuses_without_a_workspace(): void
    {
        $user = User::factory()->create(['workspace_id' => null]);

        $this->assertFalse(app(OnboardingService::class)->markStep($user, null, 'connect_first_channel', verify: false));
        $this->assertSame(0, DB::table('onboarding_steps')->count());
    }
}
