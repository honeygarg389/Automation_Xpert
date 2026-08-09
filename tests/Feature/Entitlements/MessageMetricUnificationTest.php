<?php

namespace Tests\Feature\Entitlements;

use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Jobs\SendCampaignMessageJob;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Broadcasting\Services\CampaignPersonalizer;
use App\Modules\Entitlements\Support\MessageMetrics;
use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ ONE METRIC, AND BOTH PATHS MUST TOUCH IT — SEPARATELY PROVEN.
 *
 * The defect was not the duplicated metric. It was that the two halves of
 * enforcement sat on opposite paths:
 *
 *   campaign send    incremented `whatsapp_messages`, was never checked
 *   inbox reply      was checked against it, never incremented it
 *
 * Unifying the metric alone would have made campaigns count and left replies
 * invisible — a half-fix that looks whole. So every behaviour below is asserted
 * for EACH path independently. A test that exercises one and infers the other
 * would reproduce exactly the reasoning that let this ship.
 */
class MessageMetricUnificationTest extends TestCase
{
    use RefreshDatabase;

    private const METRIC = 'messages_whatsapp';

    /** @return array{workspace: Workspace, user: User, client: Client} */
    private function customerOn(?array $limits = null): array
    {
        $plan = Plan::factory()->create(['limits' => $limits ?? ['whatsapp_messages_per_month' => 3]]);
        $client = Client::factory()->create();

        ClientSubscription::create([
            'client_id' => $client->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'status' => 'active',
        ]);

        $user = User::factory()->create([
            'role' => 'client', 'client_id' => $client->id, 'email_verified_at' => now(),
        ]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id, 'client_id' => $client->id]);
        $user->update(['workspace_id' => $workspace->id]);

        return ['workspace' => $workspace, 'user' => $user->refresh(), 'client' => $client];
    }

    private function meter(int $workspaceId, string $metric = self::METRIC): int
    {
        return (int) DB::table('usage_meters')
            ->where('workspace_id', $workspaceId)->where('metric', $metric)
            ->where('period', (int) now()->format('Ym'))->value('value');
    }

    // ══ The declaration ════════════════════════════════════════════════════

    #[Test]
    public function the_routes_and_the_senders_name_the_same_metric(): void
    {
        $routeMetrics = [];
        foreach (app('router')->getRoutes() as $r) {
            foreach ($r->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'limit:whatsapp_messages_per_month')) {
                    $routeMetrics[] = explode(',', substr($m, 6))[1] ?? '';
                }
            }
        }

        $this->assertNotEmpty($routeMetrics, 'No route enforces the WhatsApp message limit.');

        foreach ($routeMetrics as $metric) {
            $this->assertSame(self::METRIC, $metric,
                'A route still enforces against a metric no sender writes. That is the split '
                .'this change exists to close.');
        }

        $this->assertSame(self::METRIC,
            MessageMetrics::LIMIT_TO_METRIC['whatsapp_messages_per_month']);
    }

    /** The retired duplicate must not be written by anything any more. */
    #[Test]
    public function nothing_writes_the_retired_metric(): void
    {
        $hits = shell_exec('grep -rn "'.MessageMetrics::RETIRED_METRIC.'" '
            .base_path('app').' --include=*.php | grep -v MessageMetrics.php | grep "track(" || true');

        $this->assertEmpty(trim((string) $hits),
            "Something still writes '".MessageMetrics::RETIRED_METRIC."':\n".$hits);
    }

    // ══ PATH 1 — the inbox reply ═══════════════════════════════════════════

    /** @return array{workspace: Workspace, user: User, conversation: Conversation} */
    private function inboxSetup(?array $limits = null): array
    {
        $c = $this->customerOn($limits);

        $account = ChannelAccount::withoutWorkspaceScope('reason: test fixture setup')->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $c['workspace']->id,
            'channel' => 'whatsapp',
            'status' => 'active',
            'display_name' => 'Test WA',
        ]);

        $contact = Contact::withoutWorkspaceScope('reason: test fixture setup')->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $c['workspace']->id,
            'phone_e164' => '+15550001234',
        ]);

        $conversation = Conversation::withoutWorkspaceScope('reason: test fixture setup')->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $c['workspace']->id,
            'contact_id' => $contact->id,
            'channel_account_id' => $account->id,
            'status' => 'open',
            'last_inbound_at' => now(),
            'channel' => 'whatsapp',
        ]);

        // An inbound message inside 24h — otherwise reply() short-circuits on the
        // WhatsApp session-window guard and returns a redirect, which would make
        // every assertion below fail for a reason unrelated to metering.
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'inbound',
            'status' => 'delivered',
            'sent_at' => now()->subMinute(),
        ]);

        return ['workspace' => $c['workspace'], 'user' => $c['user'], 'conversation' => $conversation];
    }

    /**
     * ⚠️ The send must actually SUCCEED.
     *
     * Both paths track only on success — a message the provider rejected is not
     * one the customer used. So a test whose driver throws would find an
     * unincremented meter and could not tell that apart from the bug it is
     * supposed to catch.
     */
    private function fakeWhatsappDriverThatSucceeds(): void
    {
        $manager = app(ChannelManager::class);
        $class = null;
        $ref = new \ReflectionProperty($manager, 'drivers');
        $ref->setAccessible(true);
        $class = $ref->getValue($manager)['whatsapp'] ?? null;

        $this->assertNotNull($class, 'No WhatsApp channel driver is registered.');

        $this->app->bind($class, fn () => new class implements ChannelDriverInterface
        {
            public function send(Message $message): string
            {
                return 'wamid.FAKE'.$message->id;
            }

            public function receiveWebhook(Request $request): array
            {
                return [];
            }

            public function verifyCreds(): bool
            {
                return true;
            }
        });
    }

    /**
     * ⚠️ THE MISSING HALF. This path was checked against the limit and never
     * incremented it.
     */
    #[Test]
    public function an_inbox_reply_increments_the_unified_metric(): void
    {
        $this->fakeWhatsappDriverThatSucceeds();
        $s = $this->inboxSetup();

        $this->assertSame(0, $this->meter($s['workspace']->id), 'Precondition: meter empty.');

        $this->actingAs($s['user'])->postJson(
            route('client.inbox.reply', $s['conversation']->uuid),
            ['body' => 'hello', 'type' => 'text']
        )->assertOk();

        $this->assertSame(1, $this->meter($s['workspace']->id),
            'An inbox reply did not increment the message meter. Before this change '
            .'InboxController had no UsageMeter call at all, so an inbox-only workspace could '
            .'never reach its limit however many replies it sent.');
    }

    /** …and it is refused once the meter reaches the limit. */
    #[Test]
    public function an_inbox_reply_is_refused_at_the_limit(): void
    {
        $this->fakeWhatsappDriverThatSucceeds();
        $s = $this->inboxSetup(['whatsapp_messages_per_month' => 3]);

        UsageMeter::track($s['workspace']->id, self::METRIC, 3);

        $this->actingAs($s['user'])->postJson(
            route('client.inbox.reply', $s['conversation']->uuid),
            ['body' => 'hello', 'type' => 'text']
        )->assertStatus(402);

        $this->assertSame(3, $this->meter($s['workspace']->id),
            'The refused reply still incremented the meter — a blocked message must not be '
            .'billed.');
    }

    /** POSITIVE CONTROL: under the limit the same request succeeds. */
    #[Test]
    public function an_inbox_reply_under_the_limit_succeeds(): void
    {
        $this->fakeWhatsappDriverThatSucceeds();
        $s = $this->inboxSetup(['whatsapp_messages_per_month' => 3]);

        UsageMeter::track($s['workspace']->id, self::METRIC, 2);

        $this->actingAs($s['user'])->postJson(
            route('client.inbox.reply', $s['conversation']->uuid),
            ['body' => 'hello', 'type' => 'text']
        )->assertOk();

        $this->assertSame(3, $this->meter($s['workspace']->id));
    }

    // ══ PATH 2 — the campaign send ═════════════════════════════════════════

    /** @return array{workspace: Workspace, campaign: Campaign, contact: Contact} */
    private function campaignSetup(?array $limits = null): array
    {
        $c = $this->customerOn($limits);

        $contact = Contact::withoutWorkspaceScope('reason: test fixture setup')->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $c['workspace']->id,
            'phone_e164' => '+15550009876',
            // Opted in, or the job short-circuits on isOptedOut() long before
            // the quota guard and every assertion below would pass or fail for
            // an unrelated reason.
            'opt_in_whatsapp' => true,
        ]);

        $campaign = Campaign::create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $c['workspace']->id,
            'name' => 'Test',
            'channel' => 'whatsapp',
            'status' => 'sending',
            'template_ref' => ['name' => 'hello_world', 'language' => 'en', 'components' => []],
        ]);

        return ['workspace' => $c['workspace'], 'campaign' => $campaign, 'contact' => $contact];
    }

    /**
     * ⚠️ THE ENFORCEMENT THAT NEVER EXISTED on the highest-volume path.
     *
     * Launch is gated by `campaigns_per_month` — a count of CAMPAIGNS. One
     * campaign to 50,000 recipients passed a limit of "5 campaigns per month"
     * without touching the message limit at all.
     *
     * Asserted through the recipient row rather than an exception, because the
     * observable that matters is that the message was not sent and the customer
     * was told why.
     */
    #[Test]
    public function a_campaign_send_is_refused_at_the_limit(): void
    {
        $s = $this->campaignSetup(['whatsapp_messages_per_month' => 3]);
        UsageMeter::track($s['workspace']->id, self::METRIC, 3);

        DB::table('campaign_recipients')->insert([
            'campaign_id' => $s['campaign']->id, 'contact_id' => $s['contact']->id,
            'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);

        WorkspaceContext::for($s['workspace']->id, function () use ($s) {
            $this->runJob(
                new SendCampaignMessageJob($s['campaign']->id, $s['contact']->id),
                [app(CampaignPersonalizer::class)]
            );
        });

        $recipient = DB::table('campaign_recipients')
            ->where('campaign_id', $s['campaign']->id)->first();

        $this->assertSame('failed', $recipient->status);
        $this->assertSame('plan_limit_reached', $recipient->failed_reason,
            'The campaign send was not refused at the message limit. Campaign volume has never '
            .'been bounded — launch counts campaigns, not messages.');

        $this->assertSame(3, $this->meter($s['workspace']->id),
            'A refused campaign message still incremented the meter.');
    }

    /**
     * POSITIVE CONTROL, and the tracking half: under the limit the job sends and
     * increments the SAME metric the inbox path uses.
     */
    #[Test]
    public function a_campaign_send_under_the_limit_increments_the_unified_metric(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.CAMP']]], 200)]);

        $s = $this->campaignSetup(['whatsapp_messages_per_month' => 10]);
        $this->giveWorkspaceAWhatsappAccount($s['workspace']->id);

        DB::table('campaign_recipients')->insert([
            'campaign_id' => $s['campaign']->id, 'contact_id' => $s['contact']->id,
            'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);

        WorkspaceContext::for($s['workspace']->id, function () use ($s) {
            $this->runJob(
                new SendCampaignMessageJob($s['campaign']->id, $s['contact']->id),
                [app(CampaignPersonalizer::class)]
            );
        });

        $this->assertSame(1, $this->meter($s['workspace']->id),
            'A campaign send did not increment messages_whatsapp — so the two paths are still '
            .'feeding different meters, which is the whole defect.');

        $this->assertSame(0, $this->meter($s['workspace']->id, MessageMetrics::RETIRED_METRIC),
            'The retired duplicate metric is still being written.');
    }

    /**
     * The minimum a workspace needs for CloudApiClient::forWorkspace() to
     * resolve: an active WABA whose ENCRYPTED credentials array carries a token,
     * and an active whatsapp ChannelAccount carrying a phone_number_id.
     *
     * Both were guessed wrong first time — the token is not a column and the
     * phone number does not come from whatsapp_phone_numbers. Without them the
     * job fails with "No WhatsApp client", which looks exactly like a metering
     * bug and is not one.
     */
    private function giveWorkspaceAWhatsappAccount(int $workspaceId): void
    {
        WhatsappBusinessAccount::create([
            'workspace_id' => $workspaceId,
            'waba_id' => 'WABA-'.Str::random(6),
            'name' => 'Test WABA',
            'status' => 'active',
            'credentials' => ['access_token' => 'test-token'],
        ]);

        ChannelAccount::withoutWorkspaceScope('reason: test fixture setup')->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'channel' => 'whatsapp',
            'status' => 'active',
            'display_name' => 'Campaign WA',
            'phone_number_id' => 'PN-'.Str::random(6),
        ]);
    }

    // ══ Both paths, one meter ══════════════════════════════════════════════

    /**
     * The assertion the whole change is for: volume from BOTH paths accumulates
     * into one counter, so the limit means what it says.
     */
    #[Test]
    public function both_paths_accumulate_into_the_same_meter(): void
    {
        $this->fakeWhatsappDriverThatSucceeds();

        $s = $this->inboxSetup(['whatsapp_messages_per_month' => 10]);

        $this->actingAs($s['user'])->postJson(
            route('client.inbox.reply', $s['conversation']->uuid),
            ['body' => 'one', 'type' => 'text']
        )->assertOk();

        UsageMeter::track($s['workspace']->id, self::METRIC, 4);   // stands in for campaign volume

        $this->assertSame(5, $this->meter($s['workspace']->id),
            'Inbox and campaign volume must land in the same counter. If they do not, a '
            .'customer can exhaust their allowance twice.');
    }
}
