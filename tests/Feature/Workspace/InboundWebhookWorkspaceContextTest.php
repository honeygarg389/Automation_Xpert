<?php

namespace Tests\Feature\Workspace;

use App\Models\Scopes\WorkspaceScope;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Services\ContactService;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0, slice 4c. The inbound path — where tenant context cannot be
 * established around the job.
 *
 * ─── Why not around the job ─────────────────────────────────────────────────
 *
 * One webhook payload can carry messages for SEVERAL workspaces. The global
 * WhatsApp callback URL is shared by every WABA, the controller dispatches the
 * whole `entry[]` array in a single job, and the `phone_number_id` that
 * identifies the tenant lives per change INSIDE it. There is no single correct
 * answer at job level — so the job declares itself cross-tenant and the driver
 * establishes context per message.
 *
 * That claim is the thing these tests exist to prove, with a two-tenant payload
 * in ONE job.
 */
class InboundWebhookWorkspaceContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();
    }

    protected function tearDown(): void
    {
        // A global scope left on a shared model class contaminates every later
        // test in this process.
        Model::clearBootedModels();
        WorkspaceContext::flush();
        parent::tearDown();
    }

    /** Apply the scope now, the way slices 6-7 will apply it permanently. */
    private function simulateScope(): void
    {
        Contact::addGlobalScope(new WorkspaceScope);
        Conversation::addGlobalScope(new WorkspaceScope);
    }

    private function whatsappAccount(int $workspaceId, string $phoneNumberId): void
    {
        DB::table('channel_accounts')->insert([
            'workspace_id' => $workspaceId,
            'channel' => 'whatsapp',
            'provider' => 'meta',
            'display_name' => 'WA '.$workspaceId,
            'phone_number_id' => $phoneNumberId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * ONE payload, TWO entries, TWO phone numbers, TWO workspaces — exactly the
     * shape Meta delivers to a shared callback URL.
     */
    private function twoTenantPayload(): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [
                [
                    'id' => 'WABA-A',
                    'changes' => [[
                        'field' => 'messages',
                        'value' => [
                            'metadata' => ['phone_number_id' => 'PN-A'],
                            'messages' => [[
                                'id' => 'wamid.AAA',
                                'from' => '8801000000001',
                                'type' => 'text',
                                'timestamp' => (string) time(),
                                'text' => ['body' => 'hello from tenant A'],
                            ]],
                        ],
                    ]],
                ],
                [
                    'id' => 'WABA-B',
                    'changes' => [[
                        'field' => 'messages',
                        'value' => [
                            'metadata' => ['phone_number_id' => 'PN-B'],
                            'messages' => [[
                                'id' => 'wamid.BBB',
                                'from' => '8802000000002',
                                'type' => 'text',
                                'timestamp' => (string) time(),
                                'text' => ['body' => 'hello from tenant B'],
                            ]],
                        ],
                    ]],
                ],
            ],
        ];
    }

    // ══ THE TEST THAT MUST NOT BE VACUOUS ══════════════════════════════════

    #[Test]
    public function one_payload_carrying_two_tenants_writes_each_message_to_its_own_workspace(): void
    {
        $this->whatsappAccount(101, 'PN-A');
        $this->whatsappAccount(202, 'PN-B');

        $this->simulateScope();

        app(WhatsappDriver::class)->processWebhookPayload($this->twoTenantPayload());

        // Read back with the scope OFF, so the assertion sees the truth rather
        // than whichever tenant happens to be current.
        $contacts = DB::table('contacts')->pluck('workspace_id', 'phone_e164');
        $messages = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->pluck('conversations.workspace_id', 'messages.provider_message_id');

        $this->assertSame(101, (int) $contacts['+8801000000001'], 'Tenant A\'s contact landed in the wrong workspace.');
        $this->assertSame(202, (int) $contacts['+8802000000002'], 'Tenant B\'s contact landed in the wrong workspace.');

        $this->assertSame(101, (int) $messages['wamid.AAA'], 'Tenant A\'s message landed in the wrong workspace.');
        $this->assertSame(202, (int) $messages['wamid.BBB'], 'Tenant B\'s message landed in the wrong workspace.');
    }

    /**
     * ⚠️ THE POSITIVE CONTROL — and the first version of it was wrong.
     *
     * I first tried pinning `for(101)` around the whole payload to simulate the
     * rejected "context around the job" design. It could not fail: the driver's
     * per-message `for()` OVERRIDES the outer one, so tenant B was still written
     * correctly. The simulation simulated nothing.
     *
     * What actually discriminates is `ContactService::upsert()`. Its lookup is
     * `Contact::withTrashed()->where(['workspace_id' => $wsId, 'phone_e164' => …])`,
     * which under the scope becomes `workspace_id = $wsId AND workspace_id =
     * $context`. With the wrong context that matches nothing, so
     * `updateOrCreate` attempts an INSERT — and `contacts` has a UNIQUE index on
     * `(workspace_id, phone_e164)`, so the database refuses it.
     *
     * The consequence is worse than the duplication I expected: the write does
     * not go to the wrong tenant, it FAILS. On the inbound path that exception
     * lands in the driver's per-message try/catch and the message is silently
     * dropped. Every message for the other tenant, gone, with a log line.
     *
     * This is why the per-message `for()` is load-bearing and not decorative:
     * the explicit `workspace_id` arguments do NOT save it, because the scope
     * ANDs a second predicate onto the same column.
     */
    #[Test]
    public function a_wrong_context_makes_the_write_fail_outright_which_is_what_the_per_message_for_prevents(): void
    {
        $this->whatsappAccount(202, 'PN-B');

        $existing = DB::table('contacts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => 202,
            'phone_e164' => '+8802000000002',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->simulateScope();

        // WRONG context — tenant A's, while upserting tenant B's contact.
        $this->expectException(UniqueConstraintViolationException::class);

        WorkspaceContext::for(101, fn () => app(ContactService::class)
            ->upsert(202, ['phone_e164' => '+8802000000002']));

        $this->assertNotNull($existing);
    }

    /**
     * The other half: with the driver establishing context per message, tenant
     * B's pre-existing contact is REUSED rather than duplicated — which is only
     * possible if the lookup ran in B's context, not A's.
     */
    #[Test]
    public function the_per_message_context_lets_the_driver_match_an_existing_contact_in_the_right_tenant(): void
    {
        $this->whatsappAccount(101, 'PN-A');
        $this->whatsappAccount(202, 'PN-B');

        DB::table('contacts')->insert([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => 202,
            'phone_e164' => '+8802000000002',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->simulateScope();

        app(WhatsappDriver::class)->processWebhookPayload($this->twoTenantPayload());

        $this->assertSame(1, DB::table('contacts')->where('phone_e164', '+8802000000002')->count(),
            'Tenant B\'s existing contact was duplicated, so the lookup did not run in B\'s context.');
        $this->assertSame(202, (int) DB::table('contacts')->where('phone_e164', '+8802000000002')->value('workspace_id'));
    }

    /** POSITIVE CONTROL: a single-tenant payload still works normally. */
    #[Test]
    public function a_single_tenant_payload_is_unaffected(): void
    {
        $this->whatsappAccount(101, 'PN-A');
        $this->simulateScope();

        $payload = $this->twoTenantPayload();
        unset($payload['entry'][1]);

        app(WhatsappDriver::class)->processWebhookPayload($payload);

        $this->assertSame(1, DB::table('messages')->count());
        $this->assertSame(101, (int) DB::table('contacts')->value('workspace_id'));
    }

    /**
     * The driver's per-message try/catch is a decision already made correctly:
     * one poisoned identifier must not stop the others. Pinned here because
     * slice 4c relies on it — an unmatched phone_number_id throws, and the
     * OTHER tenant's message must still land.
     */
    #[Test]
    public function an_unroutable_message_does_not_stop_the_other_tenants_message(): void
    {
        // Only tenant B is connected. Tenant A's PN-A matches nothing.
        $this->whatsappAccount(202, 'PN-B');
        $this->simulateScope();

        app(WhatsappDriver::class)->processWebhookPayload($this->twoTenantPayload());

        $this->assertSame(1, DB::table('messages')->count(),
            'The unroutable message stopped the payload instead of being skipped.');
        $this->assertSame(202, (int) DB::table('contacts')->value('workspace_id'));
    }

    /**
     * Guards the simulation. If addGlobalScope silently did nothing, every test
     * in this file would pass for the wrong reason.
     */
    #[Test]
    public function the_simulated_scope_is_genuinely_active(): void
    {
        $this->whatsappAccount(101, 'PN-A');
        $this->simulateScope();

        app(WhatsappDriver::class)->processWebhookPayload($this->twoTenantPayload());

        $this->assertSame(0, Contact::count(), 'The scope is not applied — it should hide everything with no context.');
        $this->assertSame(1, WorkspaceContext::for(101, fn () => Contact::count()));
    }
}
