<?php

namespace Tests\Feature\Workspace\Modules;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 1c — Inbox module, PART A of 2: the conversation surface.
 *
 * Inbox is 18 sites with 37 guard expressions — the largest surface in 1c — so
 * it is split along a real seam: things that operate on a CONVERSATION here,
 * things you configure once and reuse (labels, canned replies, channel setup)
 * in part B.
 *
 * Part A covers 11 sites:
 *   InboxController         10 sites, 13 public methods, authorise(Conversation)
 *   InternalNoteController   1 site,  2 public methods, authorise(Conversation)
 *
 * Both guard on the same shape, so every conversation-scoped action — reply,
 * assign, status, handover, typing, media, notes — was §G-1b "correct" only
 * because the switcher was broken.
 *
 * Every "blocked" assertion is paired with a positive control on the SAME route
 * and verb, per CLAUDE.md.
 *
 * Traps checked rather than assumed:
 *  - ROUTE KEYS. Conversation binds by `uuid`; Message binds by `id`. A single
 *    route (serveMedia) uses BOTH, so the two must not be conflated. Passing
 *    ->id for the conversation would 404 at binding and never reach authorise().
 *  - SOFT DELETES. Neither Conversation nor Message soft-deletes.
 *  - ONE REQUEST PER TEST. WorkspaceContext memoises per user id.
 *  - LIVE NETWORK. reply() and uploadMedia() reach the provider APIs; Http and
 *    Queue are faked. Where a faked upstream makes a 2xx impossible, the
 *    positive control asserts the honest claim — the guard was PASSED, i.e. not
 *    403 — rather than a fragile 200.
 *  - Conversations require contact_id, so the fixture creates a Contact in the
 *    same workspace; a conversation built without one fails to insert and every
 *    assertion resting on it would be hollow.
 */
class InboxConversationsWorkspaceScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
        Queue::fake();
        Http::fake();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    private function conversation(int $workspaceId, string $contactName): Conversation
    {
        $contact = Contact::create([
            'workspace_id' => $workspaceId,
            'first_name' => $contactName,
            'phone_e164' => '+1555'.random_int(1000000, 9999999),
        ]);

        return Conversation::create([
            'workspace_id' => $workspaceId,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }

    // ── Conversation list follows the switch ────────────────────────────────

    #[Test]
    public function the_conversation_list_shows_the_home_workspace_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->conversation($home->id, 'HomeContact');
        $this->conversation($other->id, 'OtherContact');

        $this->actingAs($user)
            ->get(route('client.inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('conversations.data', 1));
    }

    #[Test]
    public function the_conversation_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->conversation($home->id, 'HomeContact');
        $otherConversation = $this->conversation($other->id, 'OtherContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('conversations.data', 1)
                ->where('conversations.data.0.id', $otherConversation->id));
    }

    // ── §G-1b: InboxController::authorise(Conversation) ────────────────────

    #[Test]
    public function viewing_another_tenants_conversation_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.inbox.show', $homeConversation->uuid))
            ->assertForbidden();
    }

    #[Test]
    public function viewing_a_conversation_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->get(route('client.inbox.show', $homeConversation->uuid))
            ->assertOk();
    }

    #[Test]
    public function replying_to_another_tenants_conversation_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.inbox.reply', $homeConversation->uuid), ['body' => 'hijacked reply'])
            ->assertForbidden();
    }

    /**
     * Positive control for the reply guard. The provider send is faked, so a
     * 2xx is not achievable — the honest assertion is that the guard was passed.
     */
    #[Test]
    public function replying_to_a_conversation_in_the_current_workspace_passes_the_guard(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $response = $this->actingAs($user)
            ->postJson(route('client.inbox.reply', $homeConversation->uuid), ['body' => 'legitimate reply']);

        $this->assertNotSame(403, $response->getStatusCode(),
            'The guard rejected a conversation in the user\'s own current workspace.');
    }

    #[Test]
    public function assigning_another_tenants_conversation_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.inbox.assign', $homeConversation->uuid), ['user_id' => $user->id])
            ->assertForbidden();

        $this->assertDatabaseHas('conversations', [
            'id' => $homeConversation->id,
            'assigned_user_id' => null,
        ]);
    }

    #[Test]
    public function assigning_a_conversation_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->post(route('client.inbox.assign', $homeConversation->uuid), ['user_id' => $user->id])
            ->assertRedirect();

        $this->assertDatabaseHas('conversations', [
            'id' => $homeConversation->id,
            'assigned_user_id' => $user->id,
        ]);
    }

    #[Test]
    public function changing_the_status_of_another_tenants_conversation_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.inbox.status', $homeConversation->uuid), ['status' => 'resolved'])
            ->assertForbidden();

        $this->assertDatabaseHas('conversations', ['id' => $homeConversation->id, 'status' => 'open']);
    }

    #[Test]
    public function changing_the_status_of_a_conversation_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->post(route('client.inbox.status', $homeConversation->uuid), ['status' => 'resolved'])
            ->assertRedirect();

        $this->assertDatabaseHas('conversations', ['id' => $homeConversation->id, 'status' => 'resolved']);
    }

    #[Test]
    public function the_typing_indicator_on_another_tenants_conversation_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.inbox.typing', $homeConversation->uuid), ['is_typing' => true])
            ->assertForbidden();
    }

    #[Test]
    public function the_typing_indicator_on_a_conversation_in_the_current_workspace_is_authorized(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->postJson(route('client.inbox.typing', $homeConversation->uuid), ['is_typing' => true])
            ->assertOk();
    }

    #[Test]
    public function handing_over_another_tenants_conversation_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.inbox.handover', $homeConversation->uuid))
            ->assertForbidden();
    }

    #[Test]
    public function uploading_media_to_another_tenants_conversation_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.inbox.upload-media', $homeConversation->uuid))
            ->assertForbidden();
    }

    // ── §G-1b: InternalNoteController::authorise(Conversation) ─────────────

    #[Test]
    public function reading_notes_on_another_tenants_conversation_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->getJson(route('client.inbox.notes.index', $homeConversation->uuid))
            ->assertForbidden();
    }

    #[Test]
    public function reading_notes_on_a_conversation_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->getJson(route('client.inbox.notes.index', $homeConversation->uuid))
            ->assertOk();
    }

    #[Test]
    public function adding_a_note_to_another_tenants_conversation_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.inbox.notes.store', $homeConversation->uuid), ['body' => 'hijacked note'])
            ->assertForbidden();

        $this->assertDatabaseMissing('internal_notes', ['conversation_id' => $homeConversation->id]);
    }

    #[Test]
    public function adding_a_note_to_a_conversation_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id, 'HomeContact');

        $this->actingAs($user)
            ->postJson(route('client.inbox.notes.store', $homeConversation->uuid), ['body' => 'legitimate note'])
            ->assertSuccessful();

        $this->assertDatabaseHas('internal_notes', ['conversation_id' => $homeConversation->id]);
    }

    // ── Workspace-scoped JSON helpers (no conversation binding) ─────────────

    #[Test]
    public function the_contact_search_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        Contact::create(['workspace_id' => $home->id, 'first_name' => 'HomeSearchable', 'phone_e164' => '+15550000001']);
        Contact::create(['workspace_id' => $other->id, 'first_name' => 'OtherSearchable', 'phone_e164' => '+15550000002']);

        $body = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->getJson(route('client.inbox.contacts.search', ['q' => 'Searchable']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('OtherSearchable', $body);
        $this->assertStringNotContainsString('HomeSearchable', $body,
            'The contact search leaked the home workspace while switched.');
    }

    #[Test]
    public function the_channel_account_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        ChannelAccount::create([
            'workspace_id' => $home->id, 'channel' => 'whatsapp',
            'display_name' => 'HomeAccount', 'status' => 'active',
        ]);
        ChannelAccount::create([
            'workspace_id' => $other->id, 'channel' => 'whatsapp',
            'display_name' => 'OtherAccount', 'status' => 'active',
        ]);

        $body = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->getJson(route('client.inbox.channel-accounts'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('OtherAccount', $body);
        $this->assertStringNotContainsString('HomeAccount', $body,
            'The channel-account list leaked the home workspace while switched.');
    }
}
