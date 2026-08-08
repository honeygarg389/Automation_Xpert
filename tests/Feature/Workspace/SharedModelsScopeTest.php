<?php

namespace Tests\Feature\Workspace;

use App\Exceptions\ChannelAlreadyConnectedException;
use App\Models\Concerns\BelongsToWorkspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\ChannelAccountRouting;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0, slice 7 — Conversation, Segment, ContactTag, ChannelAccount.
 *
 * Grouped per model so a stash-check tells you WHICH trait carries which tests,
 * not merely that the group as a whole is load-bearing.
 */
class SharedModelsScopeTest extends TestCase
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

    private function contact(int $ws, string $phone): int
    {
        return DB::table('contacts')->insertGetId([
            'uuid' => (string) Str::uuid(), 'workspace_id' => $ws, 'phone_e164' => $phone,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function conversation(int $ws, int $contactId, ?string $uuid = null): string
    {
        $uuid ??= (string) Str::uuid();
        DB::table('conversations')->insert([
            'uuid' => $uuid, 'workspace_id' => $ws, 'contact_id' => $contactId,
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $uuid;
    }

    private function channelAccount(int $ws, string $channel = 'whatsapp', array $extra = []): int
    {
        return DB::table('channel_accounts')->insertGetId(array_merge([
            'workspace_id' => $ws, 'channel' => $channel, 'provider' => 'meta',
            'display_name' => 'Acct '.$ws, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    // ══════════════════ CONVERSATION — uuid key, child tables ══════════════

    #[Test]
    public function conversation_is_scoped_and_filters(): void
    {
        $this->assertContains(BelongsToWorkspace::class, class_uses_recursive(Conversation::class));

        $this->conversation(11, $this->contact(11, '+15550001111'));
        $this->conversation(22, $this->contact(22, '+15550002222'));

        $this->assertSame(2, DB::table('conversations')->count(), 'Positive control: both exist.');
        $this->assertSame(1, WorkspaceContext::for(11, fn () => Conversation::count()));
        $this->assertSame(0, Conversation::count(), 'Null context fails closed.');
    }

    /**
     * uuid route key. The owner resolving on the SAME route proves the key is
     * right, so the cross-tenant 404 cannot be "wrong route key".
     */
    #[Test]
    public function conversation_uuid_binding_resolves_for_the_owner_and_404s_across_tenants(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();

        $mine = $this->conversation((int) $ws->id, $this->contact((int) $ws->id, '+15550001111'));
        $theirs = $this->conversation(99999, $this->contact(99999, '+15550009999'));

        $this->actingAs($user)->get(route('client.inbox.show', $mine))->assertOk();
        $this->actingAs($user)->get(route('client.inbox.show', $theirs))->assertNotFound();

        $this->assertSame(1, DB::table('conversations')->where('uuid', $theirs)->count(),
            'The foreign conversation is hidden, not deleted.');
    }

    /**
     * Child tables — messages, notes, assignments — carry NO workspace_id and
     * are reachable only through the conversation. They must stay reachable for
     * the owner: the scope on the parent is what protects them.
     */
    #[Test]
    public function conversation_children_stay_reachable_for_the_owner(): void
    {
        $contactId = $this->contact(11, '+15550001111');
        $uuid = $this->conversation(11, $contactId);
        $convId = DB::table('conversations')->where('uuid', $uuid)->value('id');

        DB::table('messages')->insert([
            'conversation_id' => $convId, 'direction' => 'in', 'channel' => 'whatsapp',
            'type' => 'text', 'body' => 'hi', 'status' => 'delivered',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $count = WorkspaceContext::for(11, fn () => Conversation::find($convId)?->messages()->count());

        $this->assertSame(1, $count, 'The child relation broke under the scope.');
        $this->assertNull(WorkspaceContext::for(22, fn () => Conversation::find($convId)),
            'The conversation itself is what gates the children.');
    }

    /** The existing-row write path, on Conversation's own firstOrCreate shape. */
    #[Test]
    public function conversation_first_or_create_matches_the_existing_row_in_the_right_context(): void
    {
        $contactId = $this->contact(11, '+15550001111');
        $accountId = $this->channelAccount(11);
        $uuid = $this->conversation(11, $contactId);
        DB::table('conversations')->where('uuid', $uuid)->update(['channel_account_id' => $accountId]);

        WorkspaceContext::for(11, fn () => Conversation::firstOrCreate(
            ['workspace_id' => 11, 'contact_id' => $contactId, 'channel_account_id' => $accountId],
            ['status' => 'open']
        ));

        $this->assertSame(1, DB::table('conversations')->count(), 'A duplicate conversation was created.');
    }

    // ══════════════════ SEGMENT — id key, pivot child ══════════════════════

    #[Test]
    public function segment_is_scoped_and_filters(): void
    {
        $this->assertContains(BelongsToWorkspace::class, class_uses_recursive(Segment::class));

        foreach ([[11, 'Mine'], [22, 'Theirs']] as [$ws, $name]) {
            DB::table('segments')->insert([
                'workspace_id' => $ws, 'name' => $name, 'type' => 'static',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, DB::table('segments')->count());
        $this->assertSame(['Mine'], WorkspaceContext::for(11, fn () => Segment::pluck('name')->all()));
        $this->assertSame(0, Segment::count());
    }

    #[Test]
    public function segment_id_binding_404s_across_tenants(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();

        $mine = DB::table('segments')->insertGetId([
            'workspace_id' => $ws->id, 'name' => 'Mine', 'type' => 'static',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $theirs = DB::table('segments')->insertGetId([
            'workspace_id' => 99999, 'name' => 'Theirs', 'type' => 'static',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($user)->delete(route('client.segments.destroy', $mine))->assertRedirect();
        $this->actingAs($user)->delete(route('client.segments.destroy', $theirs))->assertNotFound();

        $this->assertSame(1, DB::table('segments')->where('id', $theirs)->count());
    }

    // ══════════════════ CONTACT TAG — composite unique ═════════════════════

    #[Test]
    public function contact_tag_is_scoped_and_filters(): void
    {
        $this->assertContains(BelongsToWorkspace::class, class_uses_recursive(ContactTag::class));

        foreach ([11, 22] as $ws) {
            DB::table('contact_tags')->insert([
                'workspace_id' => $ws, 'name' => 'vip',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, DB::table('contact_tags')->count(),
            'The same tag NAME in two workspaces is legal — UNIQUE is (workspace_id, name).');
        $this->assertSame(1, WorkspaceContext::for(11, fn () => ContactTag::count()));
    }

    /**
     * The existing-row write path. Unlike Contact and Lead, this one is safe by
     * construction: UNIQUE is composite and every firstOrCreate in the codebase
     * already keys on both columns. Pinned so that stays true.
     */
    #[Test]
    public function contact_tag_first_or_create_reuses_within_a_workspace_and_creates_across(): void
    {
        WorkspaceContext::for(11, fn () => ContactTag::firstOrCreate(['workspace_id' => 11, 'name' => 'vip']));
        WorkspaceContext::for(11, fn () => ContactTag::firstOrCreate(['workspace_id' => 11, 'name' => 'vip']));
        WorkspaceContext::for(22, fn () => ContactTag::firstOrCreate(['workspace_id' => 22, 'name' => 'vip']));

        $this->assertSame(1, DB::table('contact_tags')->where('workspace_id', 11)->count(), 'Reused within the workspace.');
        $this->assertSame(1, DB::table('contact_tags')->where('workspace_id', 22)->count(), 'Created separately across workspaces.');
    }

    // ══════════════════ CHANNEL ACCOUNT — the inbound router ═══════════════

    #[Test]
    public function channel_account_is_scoped_and_filters(): void
    {
        $this->assertContains(BelongsToWorkspace::class, class_uses_recursive(ChannelAccount::class));

        $this->channelAccount(11);
        $this->channelAccount(22);

        $this->assertSame(2, DB::table('channel_accounts')->count());
        $this->assertSame(1, WorkspaceContext::for(11, fn () => ChannelAccount::count()));
        $this->assertSame(0, ChannelAccount::count());
    }

    /**
     * ⚠️ THE PREREQUISITE PAYING OFF.
     *
     * `findForInbound()` routes every WhatsApp, Messenger and Instagram message.
     * It runs with NO authenticated user and the workspace is the ANSWER it is
     * looking for — so it must see across workspaces. Closed in slice 6, before
     * this trait went on; this is the test that proves it was needed.
     */
    #[Test]
    public function inbound_routing_still_finds_a_channel_account_with_no_workspace_context(): void
    {
        $this->channelAccount(11, 'whatsapp', ['phone_number_id' => 'PN-A']);

        $this->assertNull(WorkspaceContext::id(), 'Precondition: no context, as on the webhook path.');

        $account = app(ChannelAccountRouting::class)->findForInbound('whatsapp', ['phone_number_id' => 'PN-A']);

        $this->assertNotNull($account,
            'Inbound routing found nothing. Without its bypass, EVERY inbound message would be '
            .'dropped by the driver\'s "no channel_account match" branch — silently.');
        $this->assertSame(11, (int) $account->workspace_id);
    }

    /**
     * The other half of the prerequisite: detecting a cross-workspace claim
     * means seeing across workspaces by definition. Scoped, it would refuse
     * nothing and BUG-019 would quietly return.
     */
    #[Test]
    public function attach_detection_still_sees_another_workspaces_claim(): void
    {
        $this->channelAccount(11, 'whatsapp', ['phone_number_id' => 'PN-A']);

        $this->expectException(ChannelAlreadyConnectedException::class);

        WorkspaceContext::for(22, fn () => app(ChannelAccountRouting::class)
            ->resolveForAttach(22, 'whatsapp', ['phone_number_id' => 'PN-A']));
    }

    #[Test]
    public function channel_account_id_binding_404s_across_tenants(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();

        $theirs = $this->channelAccount(99999, 'messenger');

        $this->actingAs($user)
            ->delete(route('client.inbox.setup.destroy', $theirs))
            ->assertNotFound();

        $this->assertSame(1, DB::table('channel_accounts')->where('id', $theirs)->count());
    }
}
