<?php

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 1a-bis. Two distinct moments, deliberately behaving differently:
 *
 *  - SWITCH TIME (an explicit user action) — refuse, tell the user, log it, and
 *    leave the session untouched so they stay where they were.
 *  - RESOLUTION TIME (a stale session on some later request) — do not error, or
 *    a stale value would lock the user out of a workspace they legitimately
 *    have. Log it, discard the bad value so the session self-heals, fall back
 *    to home.
 *
 * Also covers the client_id restriction on accessibleWorkspaces() (§G-1d).
 */
class WorkspaceSwitchRejectionTest extends TestCase
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

    // ── Switch time: refuse, visibly ─────────────────────────────────────────

    #[Test]
    public function switching_to_a_foreign_workspace_is_refused_with_a_message(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign@example.com']);

        $this->actingAs($user)
            ->from(route('client.dashboard'))
            ->post(route('client.workspaces.switch'), ['workspace_id' => $foreign->id])
            ->assertRedirect(route('client.dashboard'))
            ->assertSessionHas('error');

        $this->assertSame(
            (int) $home->id,
            (int) $user->fresh()->workspace_id,
            'A refused switch must not change the home workspace.'
        );
    }

    #[Test]
    public function a_refused_switch_does_not_write_the_session(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign2@example.com']);

        $this->actingAs($user)
            ->post(route('client.workspaces.switch'), ['workspace_id' => $foreign->id])
            ->assertSessionMissing('current_workspace_id');
    }

    #[Test]
    public function a_refused_switch_is_logged(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign3@example.com']);

        Log::spy();

        $this->actingAs($user)
            ->post(route('client.workspaces.switch'), ['workspace_id' => $foreign->id]);

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context) use ($user, $foreign) {
                return $message === 'workspace.switch.denied'
                    && $context['user_id'] === $user->id
                    && $context['requested_workspace_id'] === $foreign->id;
            });
    }

    /** POSITIVE CONTROL: a legitimate switch still works. */
    #[Test]
    public function switching_to_an_accessible_workspace_still_succeeds(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->post(route('client.workspaces.switch'), ['workspace_id' => $other->id])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $this->assertSame((int) $other->id, (int) $user->fresh()->workspace_id);
        $this->assertSame($other->id, session('current_workspace_id'));
    }

    // ── Resolution time: recover, do not lock out ────────────────────────────

    #[Test]
    public function a_stale_session_workspace_falls_back_to_home_without_erroring(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign4@example.com']);

        // The request must still succeed — a stale value must never lock a user out.
        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $foreign->id])
            ->get('/app/contacts')
            ->assertOk();

        WorkspaceContext::flush();
        $this->assertSame((int) $home->id, WorkspaceContext::id());
    }

    #[Test]
    public function a_stale_session_workspace_is_discarded_so_it_self_heals(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign5@example.com']);

        $this->actingAs($user);

        // WorkspaceContext reads request()->session(), which is only bound
        // during a real HTTP request. Bind one here so the test exercises the
        // production path rather than the hasSession() short-circuit.
        $this->app['request']->setLaravelSession($this->app['session.store']);

        session(['current_workspace_id' => $foreign->id]);
        $this->assertSame($foreign->id, session('current_workspace_id'), 'precondition');

        WorkspaceContext::id();

        $this->assertNull(
            session('current_workspace_id'),
            'The rejected value must be discarded, so it does not re-log on every request.'
        );
    }

    // ── §G-1d: accessibleWorkspaces() is restricted to the user's client ─────

    #[Test]
    public function accessible_workspaces_excludes_a_workspace_from_another_client(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign6@example.com']);

        // Simulate the G-1d hazard directly: a stale pivot row pointing at
        // another client's workspace, exactly what syncWithoutDetaching would
        // leave behind if client_id ever changed.
        $foreign->members()->attach($user->id, ['role' => 'member']);
        $user->refresh();

        $accessible = $user->accessibleWorkspaces()->pluck('id')->all();

        $this->assertContains($home->id, $accessible);
        $this->assertContains($other->id, $accessible);
        $this->assertNotContains(
            $foreign->id,
            $accessible,
            'A stale cross-client membership row must grant nothing.'
        );
    }

    #[Test]
    public function a_stale_cross_client_membership_cannot_be_switched_into(): void
    {
        ['user' => $user] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign7@example.com']);

        $foreign->members()->attach($user->id, ['role' => 'member']);
        $user->refresh();

        $this->actingAs($user)
            ->post(route('client.workspaces.switch'), ['workspace_id' => $foreign->id])
            ->assertSessionHas('error');

        $this->assertNotSame((int) $foreign->id, (int) $user->fresh()->workspace_id);
    }

    /** POSITIVE CONTROL: same-client workspaces are unaffected by the filter. */
    #[Test]
    public function same_client_workspaces_remain_accessible_after_the_filter(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();

        $accessible = $user->accessibleWorkspaces();

        $this->assertCount(2, $accessible);
        $this->assertTrue($other->isAccessibleBy($user));
        $this->assertTrue($home->isAccessibleBy($user));
    }
}
