<?php

namespace Tests\Feature\Channels;

use App\Exceptions\AmbiguousChannelRoutingException;
use App\Exceptions\ChannelAlreadyConnectedException;
use App\Models\AuditLog;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Services\ChannelAccountRouting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-019. A channel routing identifier claimed by two workspaces sent every
 * inbound message to whichever row the database returned first.
 *
 * ─── The shape of the bug ───────────────────────────────────────────────────
 *
 * The attach sites keyed `firstOrNew` on workspace_id AND the identifier, so
 * another workspace's number was a MISS and a second row was INSERTED. The
 * inbound router then called `->first()` on an unordered query and picked one
 * arbitrarily — in practice the oldest — so messages kept going to the previous
 * tenant's inbox, silently, forever.
 *
 * HMAC signature verification does not bound this. It proves the payload came
 * from Meta; it says nothing about which workspace the identifier belongs to.
 */
class ChannelRoutingUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private function routing(): ChannelAccountRouting
    {
        return app(ChannelAccountRouting::class);
    }

    private function account(int $workspaceId, string $channel, array $attrs = []): ChannelAccount
    {
        return ChannelAccount::create(array_merge([
            'workspace_id' => $workspaceId,
            'channel' => $channel,
            'provider' => 'meta',
            'display_name' => 'Acct '.$workspaceId,
            'status' => 'active',
        ], $attrs));
    }

    // ══ THE POSITIVE CONTROL — the most important test in this branch ═══════

    /**
     * A legitimate SAME-workspace reconnect must behave exactly as it did before
     * the guard existed.
     *
     * This is the only case that happens today, and refusing it is the failure a
     * real customer meets first. A refusal test on its own would pass if the
     * code refused everyone — which would be a worse bug than the one being
     * fixed, because it would break every reconnect.
     */
    #[Test]
    public function a_same_workspace_whatsapp_reconnect_returns_the_existing_row_and_does_not_throw(): void
    {
        $original = $this->account(10, 'whatsapp', ['phone_number_id' => 'PN-1', 'display_name' => 'Original']);

        $resolved = $this->routing()->resolveForAttach(10, 'whatsapp', ['phone_number_id' => 'PN-1']);

        $this->assertNotNull($resolved, 'A same-workspace reconnect must find its own row, not create a second.');
        $this->assertSame($original->id, $resolved->id);
        $this->assertSame(1, ChannelAccount::count(), 'No duplicate row was created.');
    }

    #[Test]
    public function a_same_workspace_messenger_reconnect_does_not_throw(): void
    {
        $original = $this->account(10, 'messenger', ['meta_json' => ['page_id' => 'PAGE-1']]);

        $resolved = $this->routing()->resolveForAttach(10, 'messenger', ['meta_json->page_id' => 'PAGE-1']);

        $this->assertSame($original->id, $resolved?->id);
    }

    #[Test]
    public function a_same_workspace_instagram_reconnect_does_not_throw(): void
    {
        $original = $this->account(10, 'instagram', ['meta_json' => ['instagram_page_id' => 'IG-1', 'instagram_account_id' => 'IG-1']]);

        $resolved = $this->routing()->resolveForAttach(10, 'instagram', [
            'meta_json->instagram_page_id' => 'IG-1',
            'meta_json->instagram_account_id' => 'IG-1',
        ]);

        $this->assertSame($original->id, $resolved?->id);
    }

    /** A first-time connection must be allowed — returns null so the caller creates. */
    #[Test]
    public function a_brand_new_connection_is_allowed(): void
    {
        $this->assertNull($this->routing()->resolveForAttach(10, 'whatsapp', ['phone_number_id' => 'PN-NEW']));
    }

    /** A different identifier in the same workspace is a different channel. */
    #[Test]
    public function a_second_distinct_number_in_the_same_workspace_is_allowed(): void
    {
        $this->account(10, 'whatsapp', ['phone_number_id' => 'PN-1']);

        $this->assertNull($this->routing()->resolveForAttach(10, 'whatsapp', ['phone_number_id' => 'PN-2']));
    }

    // ══ The refusal — and it must fail for the RIGHT reason ════════════════

    #[Test]
    public function attaching_a_whatsapp_number_owned_by_another_workspace_is_refused(): void
    {
        $this->account(10, 'whatsapp', ['phone_number_id' => 'PN-1']);

        try {
            $this->routing()->resolveForAttach(20, 'whatsapp', ['phone_number_id' => 'PN-1']);
            $this->fail('Workspace 20 was allowed to claim a number owned by workspace 10.');
        } catch (ChannelAlreadyConnectedException $e) {
            // Assert on the EXCEPTION'S OWN DATA, not just its type: this proves
            // the guard refused for the cross-workspace reason and did not fail
            // for some unrelated error that happens to be a RuntimeException.
            $this->assertSame('whatsapp', $e->channel);
            $this->assertSame(10, $e->ownedByWorkspaceId, 'It must name the workspace that actually owns it.');
            $this->assertSame(20, $e->attemptedByWorkspaceId);
            $this->assertStringContainsString('PN-1', $e->getMessage());
        }

        $this->assertSame(1, ChannelAccount::count(), 'No second row was created.');
    }

    #[Test]
    public function attaching_a_messenger_page_owned_by_another_workspace_is_refused(): void
    {
        $this->account(10, 'messenger', ['meta_json' => ['page_id' => 'PAGE-1']]);

        try {
            $this->routing()->resolveForAttach(20, 'messenger', ['meta_json->page_id' => 'PAGE-1']);
            $this->fail('Workspace 20 claimed workspace 10\'s Facebook page.');
        } catch (ChannelAlreadyConnectedException $e) {
            $this->assertSame('messenger', $e->channel);
            $this->assertSame(10, $e->ownedByWorkspaceId);
        }
    }

    /**
     * Instagram stores its id under EITHER key and the router matches both, so
     * a collision can cross the two. A guard checking only one key would pass
     * this test's setup while leaving the hole open.
     */
    #[Test]
    public function an_instagram_collision_across_the_two_keys_is_refused(): void
    {
        // Workspace 10 stored it under instagram_account_id only…
        $this->account(10, 'instagram', ['meta_json' => ['instagram_account_id' => 'IG-1']]);

        try {
            // …and workspace 20 arrives with it as instagram_page_id.
            $this->routing()->resolveForAttach(20, 'instagram', [
                'meta_json->instagram_page_id' => 'IG-1',
                'meta_json->instagram_account_id' => 'IG-1',
            ]);
            $this->fail('An Instagram id was claimed by two workspaces through different keys.');
        } catch (ChannelAlreadyConnectedException $e) {
            $this->assertSame(10, $e->ownedByWorkspaceId);
        }
    }

    /** A different CHANNEL with the same string is not a collision. */
    #[Test]
    public function the_same_identifier_on_a_different_channel_is_not_a_collision(): void
    {
        $this->account(10, 'messenger', ['meta_json' => ['page_id' => 'SHARED']]);

        $this->assertNull($this->routing()->resolveForAttach(20, 'instagram', [
            'meta_json->instagram_page_id' => 'SHARED',
        ]), 'Channels are namespaces; a messenger page id must not block an instagram id.');
    }

    // ══ The router ═════════════════════════════════════════════════════════

    /** POSITIVE CONTROL: with exactly one match the router still routes. */
    #[Test]
    public function the_router_returns_the_single_matching_account(): void
    {
        $account = $this->account(10, 'whatsapp', ['phone_number_id' => 'PN-1']);

        $this->assertSame(
            $account->id,
            $this->routing()->findForInbound('whatsapp', ['phone_number_id' => 'PN-1'])?->id
        );
    }

    /** Unchanged behaviour: an unknown identifier returns null, as before. */
    #[Test]
    public function the_router_returns_null_for_an_unknown_identifier(): void
    {
        $this->assertNull($this->routing()->findForInbound('whatsapp', ['phone_number_id' => 'NOPE']));
    }

    /**
     * MESSENGER, not WhatsApp — and the reason matters.
     *
     * The unique index makes the ambiguous WhatsApp state unreachable: an
     * attempt to insert the second row fails at the database. That is the index
     * doing its job, and it means the router's ambiguity branch is reachable
     * ONLY for the JSON-path channels.
     *
     * Which is exactly the asymmetry this branch is built around. Messenger and
     * Instagram have no schema protection, so for them the connect-time guard is
     * the only thing standing between two workspaces and a shared page — and if
     * it is ever bypassed, this is the behaviour that catches it.
     */
    #[Test]
    public function the_router_refuses_to_guess_when_two_workspaces_claim_the_identifier(): void
    {
        // Straight to the table: the guard prevents the application creating
        // this, so legacy data or a bug are the only routes to it.
        \DB::table('channel_accounts')->insert([
            ['workspace_id' => 10, 'channel' => 'messenger', 'display_name' => 'A', 'meta_json' => json_encode(['page_id' => 'PAGE-1']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => 20, 'channel' => 'messenger', 'display_name' => 'B', 'meta_json' => json_encode(['page_id' => 'PAGE-1']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        try {
            $this->routing()->findForInbound('messenger', ['meta_json->page_id' => 'PAGE-1']);
            $this->fail('The router guessed a workspace instead of refusing.');
        } catch (AmbiguousChannelRoutingException $e) {
            $this->assertSame([10, 20], $e->workspaceIds, 'It must name every claiming workspace.');
        }
    }

    /**
     * The index is a real control, not decoration: the duplicate WhatsApp row
     * cannot be written at all, even bypassing the application entirely.
     */
    #[Test]
    public function the_database_itself_refuses_a_duplicate_whatsapp_number(): void
    {
        \DB::table('channel_accounts')->insert(
            ['workspace_id' => 10, 'channel' => 'whatsapp', 'display_name' => 'A', 'phone_number_id' => 'PN-1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );

        $this->expectException(UniqueConstraintViolationException::class);

        \DB::table('channel_accounts')->insert(
            ['workspace_id' => 20, 'channel' => 'whatsapp', 'display_name' => 'B', 'phone_number_id' => 'PN-1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /**
     * The visibility requirement: an operator must find out WITHOUT reading
     * logs, and the other messages in the payload must still be delivered.
     *
     * An audit_logs row satisfies both — it is persistent and already surfaced
     * at /admin/audit-log, and writing it does not fail the job.
     */
    #[Test]
    public function an_ambiguous_route_is_recorded_in_the_audit_log(): void
    {
        \DB::table('channel_accounts')->insert([
            ['workspace_id' => 10, 'channel' => 'messenger', 'display_name' => 'A', 'meta_json' => json_encode(['page_id' => 'PAGE-1']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => 20, 'channel' => 'messenger', 'display_name' => 'B', 'meta_json' => json_encode(['page_id' => 'PAGE-1']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        try {
            $this->routing()->findForInbound('messenger', ['meta_json->page_id' => 'PAGE-1']);
        } catch (AmbiguousChannelRoutingException) {
            // expected
        }

        $entry = AuditLog::where('action', 'channel.routing_ambiguous')->latest('id')->first();

        $this->assertNotNull($entry, 'An ambiguous route left no trace outside the logs.');
        $this->assertSame('messenger', $entry->meta['channel'] ?? null);
        $this->assertSame([10, 20], array_map('intval', $entry->meta['claimed_by_workspaces'] ?? []));
    }
}
