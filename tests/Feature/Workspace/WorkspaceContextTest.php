<?php

namespace Tests\Feature\Workspace;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\Contact;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Commit 1a. WorkspaceContext is wired into nothing yet — these tests prove it
 * behaves correctly in isolation, and separately DOCUMENT the production bug it
 * is going to fix.
 *
 * See docs/phase-0-tenant-isolation-plan.md §G-1.
 */
class WorkspaceContextTest extends TestCase
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

    // ── The fixture itself ───────────────────────────────────────────────────

    #[Test]
    public function the_two_workspace_fixture_gives_a_user_access_to_both(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->assertNotSame($home->id, $other->id);
        $this->assertSame((int) $home->id, (int) $user->workspace_id, 'home must be the primary workspace');

        $accessible = $user->accessibleWorkspaces()->pluck('id')->all();
        $this->assertContains($home->id, $accessible);
        $this->assertContains($other->id, $accessible);
    }

    // ── Resolution order ─────────────────────────────────────────────────────

    #[Test]
    public function it_returns_null_when_no_user_is_authenticated(): void
    {
        $this->assertNull(WorkspaceContext::id());
        $this->assertFalse(WorkspaceContext::has());
    }

    #[Test]
    public function it_falls_back_to_the_home_workspace_when_the_session_is_empty(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)->get('/app/contacts');

        $this->assertSame((int) $home->id, WorkspaceContext::id());
    }

    #[Test]
    public function it_honours_a_session_workspace_the_user_belongs_to(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)->withSession(['current_workspace_id' => $other->id])->get('/app/contacts');
        WorkspaceContext::flush();

        $this->assertSame((int) $other->id, WorkspaceContext::id());
    }

    /**
     * The session is client-controlled. A workspace the user does not belong to
     * must never be honoured — this is the check that stops workspace switching
     * becoming a cross-tenant read once controllers start using it.
     */
    #[Test]
    public function it_ignores_a_session_workspace_the_user_does_not_belong_to(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'other-tenant@example.com']);

        $this->actingAs($user)->withSession(['current_workspace_id' => $foreign->id])->get('/app/contacts');
        WorkspaceContext::flush();

        $this->assertSame(
            (int) $home->id,
            WorkspaceContext::id(),
            'A foreign workspace id in the session must be rejected, falling back to home.'
        );
        $this->assertNotSame((int) $foreign->id, WorkspaceContext::id());
    }

    #[Test]
    public function user_can_access_reflects_membership(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        ['home' => $foreign] = $this->createTwoWorkspaceUser(['email' => 'foreign@example.com']);

        $this->assertTrue(WorkspaceContext::userCanAccess($user, $home->id));
        $this->assertTrue(WorkspaceContext::userCanAccess($user, $other->id));
        $this->assertFalse(WorkspaceContext::userCanAccess($user, $foreign->id));
    }

    // ── Explicit override, for jobs and console ──────────────────────────────

    #[Test]
    public function for_sets_an_explicit_workspace_and_restores_it_afterwards(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->actingAs($user);

        $this->assertSame((int) $home->id, WorkspaceContext::id());

        $inner = WorkspaceContext::for($other->id, fn () => WorkspaceContext::id());

        $this->assertSame((int) $other->id, $inner, 'override must win');
        $this->assertSame((int) $home->id, WorkspaceContext::id(), 'context must be restored');
    }

    #[Test]
    public function for_restores_context_even_when_the_callback_throws(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->actingAs($user);

        try {
            WorkspaceContext::for($other->id, function () {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame((int) $home->id, WorkspaceContext::id());
    }

    #[Test]
    public function for_works_without_an_authenticated_user(): void
    {
        ['other' => $other] = $this->createTwoWorkspaceUser();

        $resolved = WorkspaceContext::for($other->id, fn () => WorkspaceContext::id());

        $this->assertSame((int) $other->id, $resolved, 'queued jobs have no auth user but must still get context');
        $this->assertNull(WorkspaceContext::id(), 'and context must not leak past the callback');
    }

    // ── Switcher behaviour, and what is still broken ─────────────────────────
    //
    // The first test below WAS a characterisation test asserting the bug. It was
    // inverted when 1c/shared migrated ContactController: the route it exercises
    // (/app/contacts) now honours the switcher, so the old assertions became
    // false and the test was flipped to assert the correct behaviour instead.
    //
    // The two tests after it are NOT controller tests and did not flip. They
    // evaluate the legacy expression directly, so they stay true for as long as
    // that expression exists anywhere in the codebase.

    /**
     * Was `characterisation_switching_workspace_does_not_affect_controllers_today`,
     * which asserted the OPPOSITE and passed until 2026-08-07.
     *
     * It flipped when 1c/shared migrated ContactController — not at 1b, and not
     * at "1c completion" as the original comment predicted. The route it
     * exercises, GET /app/contacts, is ContactController::index, one of that
     * commit's 14 sites. The moment that site resolved through WorkspaceContext,
     * the switched workspace's contact started showing and the bug-documenting
     * assertions became false.
     *
     * It is now load-bearing in the ordinary way: reverting ContactController's
     * resolution makes it fail.
     */
    #[Test]
    public function switching_workspace_changes_which_contacts_the_list_returns(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();

        Contact::factory()->create([
            'workspace_id' => $home->id,
            'first_name' => 'HomeOnly', 'last_name' => 'Contact',
            'phone_e164' => '+8801700000001',
        ]);
        Contact::factory()->create([
            'workspace_id' => $other->id,
            'first_name' => 'OtherOnly', 'last_name' => 'Contact',
            'phone_e164' => '+8801700000002',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get('/app/contacts')
            ->assertOk();

        $body = $response->getContent();

        // The switched-to workspace is honoured: its contact shows, the home
        // workspace's does not.
        $this->assertStringContainsString('OtherOnly', $body,
            'The switched-to workspace must be honoured by the contact list.');
        $this->assertStringNotContainsString('HomeOnly', $body,
            'The home workspace must no longer leak in once a switch is active.');
    }

    #[Test]
    public function characterisation_the_broken_expression_always_yields_the_home_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();

        // The literal expression used in ~90 call sites across the codebase.
        $legacy = $user->current_workspace_id ?? $user->workspace_id;

        $this->assertNull(
            $user->current_workspace_id,
            'users.current_workspace_id does not exist — not a column, accessor or cast.'
        );
        $this->assertSame((int) $home->id, (int) $legacy);
        $this->assertNotSame((int) $other->id, (int) $legacy);
    }

    /**
     * The gap 1b closes, stated as a single assertion: given the same user and
     * the same session, the new component and the legacy expression disagree.
     */
    #[Test]
    public function workspace_context_and_the_legacy_expression_currently_disagree(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)->withSession(['current_workspace_id' => $other->id])->get('/app/contacts');
        WorkspaceContext::flush();

        $legacy = $user->current_workspace_id ?? $user->workspace_id;

        $this->assertSame((int) $other->id, WorkspaceContext::id(), 'new component honours the switch');
        $this->assertNotSame((int) $legacy, WorkspaceContext::id(), 'legacy expression does not');
    }
}
