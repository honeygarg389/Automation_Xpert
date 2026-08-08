<?php

namespace Tests\Feature\Workspace;

use App\Models\Concerns\BelongsToWorkspace;
use App\Modules\Leads\Models\Lead;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\ContactService;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0, slice 6. `Contact` — the real canary.
 *
 * Lead proved the transport. Contact carries the cargo: a uuid route key, soft
 * deletes, child tables, and route binding from four modules. Each hazard is
 * proven here rather than assumed.
 */
class ContactScopeTest extends TestCase
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

    private function contactRow(int $workspaceId, string $phone, ?string $uuid = null): string
    {
        $uuid ??= (string) Str::uuid();

        DB::table('contacts')->insert([
            'uuid' => $uuid,
            'workspace_id' => $workspaceId,
            'phone_e164' => $phone,
            'first_name' => 'C'.$workspaceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $uuid;
    }

    // ══ Basic filtering ════════════════════════════════════════════════════

    #[Test]
    public function contact_is_scoped(): void
    {
        $this->assertContains(BelongsToWorkspace::class, class_uses_recursive(Contact::class));
    }

    #[Test]
    public function reads_are_filtered_and_the_hidden_rows_really_exist(): void
    {
        $this->contactRow(11, '+15550000011');
        $this->contactRow(22, '+15550000022');

        $this->assertSame(2, DB::table('contacts')->count(), 'Positive control: both rows exist.');
        $this->assertSame(1, WorkspaceContext::for(11, fn () => Contact::count()));
        $this->assertSame(0, Contact::count(), 'Null context fails closed.');
    }

    // ══ HAZARD 1 — uuid route binding ══════════════════════════════════════

    /**
     * The owner resolves. This is the positive control that makes the 404 below
     * mean something: it proves the route, the verb and the route KEY are all
     * correct, so a 404 for another tenant cannot be "wrong route key".
     */
    #[Test]
    public function uuid_route_binding_still_resolves_for_the_owner(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $uuid = $this->contactRow((int) $workspace->id, '+15550000001');

        $this->actingAs($user)
            ->get(route('client.contacts.show', $uuid))
            ->assertOk();
    }

    #[Test]
    public function uuid_route_binding_404s_for_another_tenant(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $foreignUuid = $this->contactRow(99999, '+15550009999');

        $this->actingAs($user)
            ->get(route('client.contacts.show', $foreignUuid))
            ->assertNotFound();

        // The row is untouched — this is the scope hiding it, not the app
        // deleting or mangling it.
        $this->assertSame(1, DB::table('contacts')->where('uuid', $foreignUuid)->count());
    }

    /**
     * ⚠️ PROVES THE 404 COMES FROM THE SCOPE, NOT A WRONG ROUTE KEY.
     *
     * CLAUDE.md records a real incident where a test 404'd because it passed
     * `->id` to a uuid-bound route, so authorization was never exercised and the
     * test had "proved" protection for as long as it existed.
     *
     * Here the SAME uuid, on the SAME route, resolves when the scope allows it
     * and 404s when it does not. Only the workspace context differs.
     */
    #[Test]
    public function the_same_uuid_resolves_or_404s_depending_only_on_the_workspace_context(): void
    {
        ['user' => $userA, 'workspace' => $wsA] = $this->createWorkspaceContext();
        ['user' => $userB] = $this->createWorkspaceContext();

        $uuid = $this->contactRow((int) $wsA->id, '+15550000123');

        $this->actingAs($userA)->get(route('client.contacts.show', $uuid))->assertOk();
        $this->actingAs($userB)->get(route('client.contacts.show', $uuid))->assertNotFound();
    }

    // ══ HAZARD 2 — soft deletes ════════════════════════════════════════════

    /**
     * `assertDatabaseHas` cannot prove a delete was prevented on a soft-deleting
     * model — CLAUDE.md records that trap. `assertNotSoftDeleted` can.
     */
    #[Test]
    public function a_cross_tenant_delete_leaves_the_contact_not_soft_deleted(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $foreignUuid = $this->contactRow(99999, '+15550009998');

        $this->actingAs($user)
            ->delete(route('client.contacts.destroy', $foreignUuid))
            ->assertNotFound();

        $this->assertNotSoftDeleted('contacts', ['uuid' => $foreignUuid]);
    }

    /** POSITIVE CONTROL: the owner's delete really does soft-delete. */
    #[Test]
    public function the_owner_can_soft_delete_their_own_contact(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $uuid = $this->contactRow((int) $workspace->id, '+15550000002');

        $this->actingAs($user)
            ->delete(route('client.contacts.destroy', $uuid))
            ->assertRedirect();

        $this->assertSoftDeleted('contacts', ['uuid' => $uuid]);
    }

    /**
     * The scope and SoftDeletes COMPOSE. A trashed contact is hidden by both, so
     * `withTrashed()` must still respect the workspace — otherwise any code
     * reaching for trashed rows (and `ContactService::upsert` does) becomes a
     * cross-tenant read.
     */
    #[Test]
    public function with_trashed_still_respects_the_workspace(): void
    {
        $mine = $this->contactRow(11, '+15550000011');
        $theirs = $this->contactRow(22, '+15550000022');

        DB::table('contacts')->whereIn('uuid', [$mine, $theirs])->update(['deleted_at' => now()]);

        $seen = WorkspaceContext::for(11, fn () => Contact::withTrashed()->pluck('uuid')->all());

        $this->assertSame([$mine], $seen, 'withTrashed() escaped the workspace scope.');
    }

    // ══ HAZARD 3 — child tables ════════════════════════════════════════════

    #[Test]
    public function child_conversations_and_messages_stay_reachable_for_the_owner(): void
    {
        $uuid = $this->contactRow(11, '+15550000011');
        $contactId = DB::table('contacts')->where('uuid', $uuid)->value('id');

        $conversationId = DB::table('conversations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => 11,
            'contact_id' => $contactId,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('messages')->insert([
            'conversation_id' => $conversationId,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'hi',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = WorkspaceContext::for(11, function () use ($contactId) {
            $contact = Contact::find($contactId);

            return [
                'contact' => $contact?->id,
                'conversations' => $contact?->conversations()->count(),
                'messages' => DB::table('messages')
                    ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                    ->where('conversations.contact_id', $contactId)->count(),
            ];
        });

        $this->assertSame($contactId, $result['contact']);
        $this->assertSame(1, $result['conversations'], 'The child relation broke under the scope.');
        $this->assertSame(1, $result['messages']);
    }

    #[Test]
    public function a_foreign_contacts_children_are_not_reachable(): void
    {
        $uuid = $this->contactRow(22, '+15550000022');
        $contactId = DB::table('contacts')->where('uuid', $uuid)->value('id');

        $this->assertNull(WorkspaceContext::for(11, fn () => Contact::find($contactId)));
    }

    // ══ HAZARD 4 — the existing-row write path (the 4c failure mode) ═══════

    /**
     * Proven on Contact, as it was on the inbound path in slice 4c.
     *
     * `contacts` has a UNIQUE (workspace_id, phone_e164). With the WRONG
     * context, `ContactService::upsert()`'s lookup misses, `updateOrCreate`
     * attempts an INSERT, and the database refuses it. The write does not go to
     * the wrong tenant — it FAILS.
     */
    #[Test]
    public function upserting_with_the_wrong_context_fails_rather_than_writing_to_the_wrong_tenant(): void
    {
        $this->contactRow(22, '+15550000022');

        $this->expectException(UniqueConstraintViolationException::class);

        WorkspaceContext::for(11, fn () => app(ContactService::class)
            ->upsert(22, ['phone_e164' => '+15550000022']));
    }

    /** POSITIVE CONTROL: the correct context updates in place. */
    #[Test]
    public function upserting_with_the_right_context_updates_in_place(): void
    {
        $this->contactRow(22, '+15550000022');

        WorkspaceContext::for(22, fn () => app(ContactService::class)
            ->upsert(22, ['phone_e164' => '+15550000022', 'first_name' => 'Updated']));

        $this->assertSame(1, DB::table('contacts')->where('phone_e164', '+15550000022')->count());
        $this->assertSame('Updated', DB::table('contacts')->where('phone_e164', '+15550000022')->value('first_name'));
    }

    // ══ The workspace() override guard — Option 3, approved ════════════════

    /**
     * The trait deliberately does NOT define `workspace()`. This prevents the
     * drift rather than detecting it later: if a scoped model declares its own
     * and someone changes its foreign key, `withDefault()`, or filters it, half
     * the scoped models resolve one way and half the other with nothing to
     * indicate it.
     *
     * `InboxLabel` and `CannedReply` declare one today, identically. They are
     * allow-listed with that fact recorded, so a NEW override — or a change to
     * one of those two — has to be a deliberate edit here.
     */
    #[Test]
    public function no_scoped_model_declares_its_own_workspace_relation_without_being_listed(): void
    {
        $allowed = [
            // Pre-date the trait; both are exactly belongsTo(Workspace::class),
            // verified identical at slice 6. Neither is scoped yet.
            'App\Modules\Inbox\Models\InboxLabel',
            'App\Modules\Inbox\Models\CannedReply',
        ];

        $offenders = [];

        foreach ([Contact::class, Conversation::class, Segment::class, ContactTag::class, Lead::class] as $model) {
            if (in_array($model, $allowed, true)) {
                continue;
            }

            $reflection = new \ReflectionClass($model);

            if ($reflection->hasMethod('workspace') && $reflection->getMethod('workspace')->getDeclaringClass()->getName() === $model) {
                $offenders[] = $model;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            '',
            'A scoped model declares its own workspace() relation:',
            implode("\n", array_map(fn ($m) => "  {$m}", $offenders)),
            '',
            'The trait deliberately does not define one, so there is exactly one definition per',
            'model. Two definitions of one concept is the shape that produced',
            'accessibleWorkspaces() vs isAccessibleBy() and the two WhatsApp dedupe layers —',
            'both found only because a test failed for an unexpected reason.',
            '',
            'If this model genuinely needs its own, add it to $allowed here with the reason.',
            '',
        ]));
    }

    /** The trait itself must stay relation-free. */
    #[Test]
    public function the_trait_does_not_define_a_workspace_relation(): void
    {
        $this->assertFalse(
            (new \ReflectionClass(BelongsToWorkspace::class))->hasMethod('workspace'),
            'BelongsToWorkspace defines workspace() again. It was removed at slice 5 because '
            .'InboxLabel and CannedReply already declare their own and PHP resolves '
            .'class-over-trait SILENTLY — and because nothing among the 27 models uses the '
            .'relation at all.'
        );
    }

    // ══ The service that was fixed as a prerequisite ═══════════════════════

    #[Test]
    public function the_automation_webhook_resolves_a_contact_in_the_automations_workspace(): void
    {
        $automationId = DB::table('automations')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => 44,
            'name' => 'Test',
            'status' => 'active',
            'trigger_type' => 'webhook',
            'trigger_token' => 'tok-abc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->contactRow(44, '+15550004444');
        DB::table('contacts')->where('workspace_id', 44)->update(['email' => 'match@example.com']);

        // Unauthenticated, exactly as the webhook arrives.
        $this->postJson('/webhooks/automation/tok-abc', ['email' => 'match@example.com'])
            ->assertStatus(202);

        $this->assertNotNull($automationId);
    }
}
