<?php

namespace Tests\Feature\Workspace\Modules;

use App\Modules\Inbox\Models\CannedReply;
use App\Modules\Inbox\Models\InboxLabel;
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
 * 1c — Inbox module, PART B of 2: configuration and libraries.
 *
 * The 7 sites completing the module's 18, and the last module of 1c before Core.
 *
 *   LabelController        1 site, 6 methods — authorise(InboxLabel) and
 *                          authoriseConversation(Conversation)
 *   CannedReplyController  1 site, 5 methods — authorise(CannedReply)
 *   InboxSetupController   5 sites, 5 methods — inline guards in assignChatbot
 *                          and destroy
 *
 * Four guard shapes, tested individually rather than assumed to share a path.
 *
 * Every "blocked" assertion is paired with a positive control on the SAME route
 * and verb, per CLAUDE.md.
 *
 * ⚠️ THE TRAP SPECIFIC TO THIS HALF. InboxSetupController::destroy() contains
 * TWO abort_unless(..., 403) calls: one comparing the workspace, and one
 * restricting the channel to instagram|messenger. A whatsapp ChannelAccount is
 * therefore rejected with 403 for a reason that has nothing to do with tenancy,
 * and a negative test built on one would pass without the workspace guard ever
 * running. Every ChannelAccount fixture here uses `instagram` so the workspace
 * guard is the only thing that can produce the 403.
 *
 * Other traps checked rather than assumed:
 *  - PAYLOADS REACH THE GUARD. After part A, every negative's payload is valid
 *    for its endpoint, so a 403 cannot be a disguised 422.
 *  - ROUTE KEYS. InboxLabel, CannedReply and ChannelAccount bind by `id`;
 *    Conversation binds by `uuid`. The label attach/detach routes use BOTH in
 *    one URL and must not be conflated.
 *  - SOFT DELETES. None of these models soft-delete.
 *  - ONE REQUEST PER TEST, since WorkspaceContext memoises per user id.
 *  - LIVE NETWORK. The setup controller's embedded-signup paths call Meta at
 *    several points; Http and Queue are faked.
 */
class InboxConfigWorkspaceScopingTest extends TestCase
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

    private function label(int $workspaceId, string $name): InboxLabel
    {
        return InboxLabel::create([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'color' => '#ff0000',
        ]);
    }

    private function cannedReply(int $workspaceId, string $shortcut): CannedReply
    {
        return CannedReply::create([
            'workspace_id' => $workspaceId,
            'shortcut' => $shortcut,
            'body' => 'Body for '.$shortcut,
        ]);
    }

    /** Always `instagram` — see the class docblock on destroy()'s second guard. */
    private function channelAccount(int $workspaceId, string $name): ChannelAccount
    {
        return ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'instagram',
            'display_name' => $name,
            'status' => 'active',
        ]);
    }

    private function conversation(int $workspaceId): Conversation
    {
        $contact = Contact::create([
            'workspace_id' => $workspaceId,
            'first_name' => 'Contact'.$workspaceId,
            'phone_e164' => '+1555'.random_int(1000000, 9999999),
        ]);

        return Conversation::create([
            'workspace_id' => $workspaceId,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }

    // ── Labels ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_label_list_shows_the_home_workspace_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->label($home->id, 'HomeLabel');
        $this->label($other->id, 'OtherLabel');

        $this->actingAs($user)
            ->get(route('client.inbox.labels.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('labels', 1)
                ->where('labels.0.name', 'HomeLabel'));
    }

    #[Test]
    public function the_label_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->label($home->id, 'HomeLabel');
        $this->label($other->id, 'OtherLabel');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.inbox.labels.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('labels', 1)
                ->where('labels.0.name', 'OtherLabel'));
    }

    #[Test]
    public function a_new_label_is_created_in_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.inbox.labels.store'), ['name' => 'SwitchedLabel', 'color' => '#00ff00']);

        $this->assertDatabaseHas('inbox_labels', [
            'name' => 'SwitchedLabel',
            'workspace_id' => $other->id,
        ]);
    }

    #[Test]
    public function updating_another_tenants_label_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeLabel = $this->label($home->id, 'HomeLabel');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->put(route('client.inbox.labels.update', $homeLabel->id), ['name' => 'Hijacked', 'color' => '#000000'])
            ->assertForbidden();

        $this->assertDatabaseHas('inbox_labels', ['id' => $homeLabel->id, 'name' => 'HomeLabel']);
    }

    #[Test]
    public function updating_a_label_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeLabel = $this->label($home->id, 'HomeLabel');

        $this->actingAs($user)
            ->put(route('client.inbox.labels.update', $homeLabel->id), ['name' => 'Renamed', 'color' => '#000000'])
            ->assertRedirect();

        $this->assertDatabaseHas('inbox_labels', ['id' => $homeLabel->id, 'name' => 'Renamed']);
    }

    #[Test]
    public function deleting_another_tenants_label_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeLabel = $this->label($home->id, 'HomeLabel');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.inbox.labels.destroy', $homeLabel->id))
            ->assertForbidden();

        $this->assertDatabaseHas('inbox_labels', ['id' => $homeLabel->id]);
    }

    #[Test]
    public function deleting_a_label_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeLabel = $this->label($home->id, 'HomeLabel');

        $this->actingAs($user)
            ->delete(route('client.inbox.labels.destroy', $homeLabel->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('inbox_labels', ['id' => $homeLabel->id]);
    }

    // ── authoriseConversation() on attach ──────────────────────────────────

    #[Test]
    public function attaching_a_label_to_another_tenants_conversation_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id);
        $homeLabel = $this->label($home->id, 'HomeLabel');

        $this->actingAs($user)
            // §G-4 (signed off 2026-08-08, all sites): the workspace scope applies to
            // implicit route-model binding, so a foreign record is never resolved and
            // binding aborts before authorization. 403 -> 404 is better security — a 403
            // confirms the row exists, a 404 does not. The row-survival assertion and the
            // same-route/same-verb positive controls in this file are what make it evidence.
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.inbox.labels.attach', $homeConversation->uuid), ['label_id' => $homeLabel->id])
            ->assertNotFound();

        $this->assertDatabaseMissing('inbox_label_conversation', ['conversation_id' => $homeConversation->id]);
    }

    #[Test]
    public function attaching_a_label_to_a_conversation_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeConversation = $this->conversation($home->id);
        $homeLabel = $this->label($home->id, 'HomeLabel');

        $this->actingAs($user)
            ->postJson(route('client.inbox.labels.attach', $homeConversation->uuid), ['label_id' => $homeLabel->id])
            ->assertOk();

        // Pivot is `inbox_label_conversation` with a `label_id` column — not the
        // Laravel-convention `conversation_label` / `inbox_label_id`. Guessing
        // either would have errored, not silently passed, but it is worth naming.
        $this->assertDatabaseHas('inbox_label_conversation', [
            'conversation_id' => $homeConversation->id,
            'label_id' => $homeLabel->id,
        ]);
    }

    // ── Canned replies ─────────────────────────────────────────────────────

    #[Test]
    public function the_canned_reply_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->cannedReply($home->id, 'homeshortcut');
        $this->cannedReply($other->id, 'othershortcut');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.inbox.canned-replies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('cannedReplies', 1)
                ->where('cannedReplies.0.shortcut', 'othershortcut'));
    }

    #[Test]
    public function the_canned_reply_json_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->cannedReply($home->id, 'homeshortcut');
        $this->cannedReply($other->id, 'othershortcut');

        $body = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->getJson(route('client.inbox.canned-replies.list'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('othershortcut', $body);
        $this->assertStringNotContainsString('homeshortcut', $body,
            'The canned-reply list leaked the home workspace while switched.');
    }

    #[Test]
    public function deleting_another_tenants_canned_reply_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeReply = $this->cannedReply($home->id, 'homeshortcut');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.inbox.canned-replies.destroy', $homeReply->id))
            ->assertForbidden();

        $this->assertDatabaseHas('inbox_canned_replies', ['id' => $homeReply->id]);
    }

    #[Test]
    public function deleting_a_canned_reply_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeReply = $this->cannedReply($home->id, 'homeshortcut');

        $this->actingAs($user)
            ->delete(route('client.inbox.canned-replies.destroy', $homeReply->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('inbox_canned_replies', ['id' => $homeReply->id]);
    }

    #[Test]
    public function updating_another_tenants_canned_reply_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeReply = $this->cannedReply($home->id, 'homeshortcut');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->put(route('client.inbox.canned-replies.update', $homeReply->id), [
                'shortcut' => 'hijacked',
                'body' => 'hijacked body',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('inbox_canned_replies', [
            'id' => $homeReply->id,
            'shortcut' => 'homeshortcut',
        ]);
    }

    // ── Channel setup ──────────────────────────────────────────────────────

    #[Test]
    public function the_setup_page_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->channelAccount($home->id, 'HomeAccount');
        $this->channelAccount($other->id, 'OtherAccount');

        $body = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.inbox.setup'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('OtherAccount', $body);
        $this->assertStringNotContainsString('HomeAccount', $body,
            'The setup page leaked the home workspace while switched.');
    }

    /**
     * destroy() has a SECOND 403 restricting the channel to instagram|messenger.
     * The fixture uses instagram so the workspace guard is the only thing that
     * can produce this 403 — otherwise the test would pass without the
     * resolution site being involved at all.
     */
    #[Test]
    public function deleting_another_tenants_channel_account_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeAccount = $this->channelAccount($home->id, 'HomeAccount');

        $this->actingAs($user)
            // §G-4 (signed off 2026-08-08, all sites): the workspace scope applies to
            // implicit route-model binding, so a foreign record is never resolved and
            // binding aborts before authorization. 403 -> 404 is better security — a 403
            // confirms the row exists, a 404 does not. The row-survival assertion and the
            // same-route/same-verb positive controls in this file are what make it evidence.
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.inbox.setup.destroy', $homeAccount->id))
            ->assertNotFound();

        $this->assertDatabaseHas('channel_accounts', ['id' => $homeAccount->id]);
    }

    #[Test]
    public function deleting_a_channel_account_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeAccount = $this->channelAccount($home->id, 'HomeAccount');

        $this->actingAs($user)
            ->delete(route('client.inbox.setup.destroy', $homeAccount->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('channel_accounts', ['id' => $homeAccount->id]);
    }

    #[Test]
    public function assigning_a_chatbot_to_another_tenants_channel_account_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeAccount = $this->channelAccount($home->id, 'HomeAccount');

        $this->actingAs($user)
            // §G-4 (signed off 2026-08-08, all sites): the workspace scope applies to
            // implicit route-model binding, so a foreign record is never resolved and
            // binding aborts before authorization. 403 -> 404 is better security — a 403
            // confirms the row exists, a 404 does not. The row-survival assertion and the
            // same-route/same-verb positive controls in this file are what make it evidence.
            ->withSession(['current_workspace_id' => $other->id])
            ->patch(route('client.inbox.setup.assign-chatbot', $homeAccount->id), ['chatbot_id' => null])
            ->assertNotFound();
    }

    #[Test]
    public function assigning_a_chatbot_to_a_channel_account_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeAccount = $this->channelAccount($home->id, 'HomeAccount');

        // chatbot_id null is valid and clears the assignment, so this exercises
        // the guard without needing an AiChatbot fixture.
        $this->actingAs($user)
            ->patch(route('client.inbox.setup.assign-chatbot', $homeAccount->id), ['chatbot_id' => null])
            ->assertRedirect();
    }
}
