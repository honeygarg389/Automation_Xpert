<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function clientUser(): User
    {
        // Phase 0 slice 9 (BUG-009): onboarding progress is recorded PER
        // WORKSPACE now, so a user with no workspace cannot complete a step —
        // markStep() refuses rather than writing a null. UserFactory creates no
        // workspace, so build a real context, which is what signup does.
        ['user' => $user] = $this->createWorkspaceContext([], [
            'role' => 'client',
            'email_verified_at' => now(),
        ]);

        return $user;
    }

    public function test_user_can_view_onboarding_wizard(): void
    {
        $user = $this->clientUser();
        $this->actingAs($user)
            ->get(route('client.onboarding.show'))
            ->assertOk();
    }

    public function test_user_can_complete_a_step(): void
    {
        $user = $this->clientUser();

        $this->actingAs($user)
            ->postJson(route('client.onboarding.complete'), ['step' => 'connect_first_channel'])
            ->assertOk();

        // The workspace is now part of the row's identity — asserting without it
        // would pass even if the step were recorded against the wrong workspace,
        // which is exactly the bug (BUG-009) this closes.
        $this->assertDatabaseHas('onboarding_steps', [
            'user_id' => $user->id,
            'workspace_id' => $user->workspace_id,
            'step' => 'connect_first_channel',
        ]);
    }

    public function test_guest_cannot_view_onboarding(): void
    {
        $this->get(route('client.onboarding.show'))
            ->assertRedirect(route('login'));
    }
}
