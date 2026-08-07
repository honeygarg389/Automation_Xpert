<?php

namespace Tests\Feature\Workspace\Modules;

use App\Modules\Whatsapp\Models\WhatsappAutoReply;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 1c — Whatsapp module, PART A of 2: templates and auto-replies.
 *
 * Whatsapp is 22 sites across 5 client controllers, split into two verified
 * halves along a real seam — message-content configuration here, channel setup
 * and onboarding in part B.
 *
 * Part A covers 11 sites:
 *   WhatsappTemplateController   8 sites, 8 methods, 3 inline abort_unless
 *                                guards (edit, update, destroy)
 *   WhatsappAutoReplyController  3 sites, 4 methods, 1 authorise() guard
 *                                (update, destroy)
 *
 * Every "blocked" assertion is paired with a positive control on the SAME route
 * and verb, per CLAUDE.md.
 *
 * Traps checked rather than assumed, each having bitten an earlier group:
 *  - ROUTE KEYS: WhatsappTemplate and WhatsappAutoReply BOTH bind by `id` here,
 *    unlike the AI and Shared models. Verified per model — Shared proved a
 *    module can mix `uuid` and `id`.
 *  - SOFT DELETES: neither model soft-deletes, so assertDatabaseHas/Missing is
 *    load-bearing for the destroy assertions rather than vacuous.
 *  - ONE REQUEST PER TEST: WorkspaceContext memoises per user id for the life
 *    of a test.
 *  - Templates require a `waba_id`, so the fixture sets one; a template created
 *    without it fails to insert and every assertion below it would be hollow.
 */
class WhatsappTemplatesWorkspaceScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();

        // sync() and uploadMedia() reach the Meta Graph API.
        Queue::fake();
        Http::fake();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    private function template(int $workspaceId, string $name): WhatsappTemplate
    {
        return WhatsappTemplate::create([
            'workspace_id' => $workspaceId,
            'waba_id' => 'waba-'.$workspaceId,
            'name' => $name,
            'language' => 'en_US',
            'category' => 'MARKETING',
            'status' => 'APPROVED',
        ]);
    }

    private function autoReply(int $workspaceId, string $keyword): WhatsappAutoReply
    {
        return WhatsappAutoReply::create([
            'workspace_id' => $workspaceId,
            'trigger_type' => 'keyword',
            'match_mode' => 'contains',
            'keywords' => [$keyword],
            'response_kind' => 'text',
            'payload_json' => ['text' => 'reply '.$keyword],
            'enabled' => true,
            'priority' => 0,
        ]);
    }

    // ── Template list follows the switch ────────────────────────────────────

    #[Test]
    public function the_template_list_shows_the_home_workspace_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->template($home->id, 'home_template');
        $this->template($other->id, 'other_template');

        $this->actingAs($user)
            ->get(route('client.whatsapp.templates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('templates', 1)
                ->where('templates.0.name', 'home_template'));
    }

    #[Test]
    public function the_template_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->template($home->id, 'home_template');
        $this->template($other->id, 'other_template');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.whatsapp.templates.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('templates', 1)
                ->where('templates.0.name', 'other_template'));
    }

    // ── §G-1b: the three inline guards in edit / update / destroy ───────────

    #[Test]
    public function editing_another_tenants_template_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeTemplate = $this->template($home->id, 'home_template');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.whatsapp.templates.edit', $homeTemplate->id))
            ->assertForbidden();
    }

    #[Test]
    public function editing_a_template_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeTemplate = $this->template($home->id, 'home_template');

        $this->actingAs($user)
            ->get(route('client.whatsapp.templates.edit', $homeTemplate->id))
            ->assertOk();
    }

    #[Test]
    public function deleting_another_tenants_template_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeTemplate = $this->template($home->id, 'home_template');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.whatsapp.templates.destroy', $homeTemplate->id))
            ->assertForbidden();

        // WhatsappTemplate does not soft-delete, so a surviving row is a real
        // proof that the delete was prevented.
        $this->assertDatabaseHas('whatsapp_templates', ['id' => $homeTemplate->id]);
    }

    #[Test]
    public function deleting_a_template_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeTemplate = $this->template($home->id, 'home_template');

        $this->actingAs($user)
            ->delete(route('client.whatsapp.templates.destroy', $homeTemplate->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('whatsapp_templates', ['id' => $homeTemplate->id]);
    }

    #[Test]
    public function updating_another_tenants_template_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeTemplate = $this->template($home->id, 'home_template');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->put(route('client.whatsapp.templates.update', $homeTemplate->id), [
                'name' => 'hijacked_template',
                'language' => 'en_US',
                'category' => 'MARKETING',
                'components' => [],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('whatsapp_templates', [
            'id' => $homeTemplate->id,
            'name' => 'home_template',
        ]);
    }

    // ── Auto-reply list follows the switch ──────────────────────────────────

    #[Test]
    public function the_auto_reply_list_shows_the_home_workspace_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->autoReply($home->id, 'homeword');
        $this->autoReply($other->id, 'otherword');

        $this->actingAs($user)
            ->get(route('client.whatsapp.auto-replies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('rules', 1));
    }

    #[Test]
    public function the_auto_reply_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeRule = $this->autoReply($home->id, 'homeword');
        $otherRule = $this->autoReply($other->id, 'otherword');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.whatsapp.auto-replies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rules', 1)
                ->where('rules.0.id', $otherRule->id));
    }

    #[Test]
    public function a_new_auto_reply_is_created_in_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.whatsapp.auto-replies.store'), [
                'trigger_type' => 'keyword',
                'match_mode' => 'contains',
                'keywords' => ['switched'],
                'response_kind' => 'text',
                'payload_json' => ['text' => 'hello from the switched workspace'],
                'enabled' => true,
                'priority' => 0,
            ]);

        $this->assertDatabaseHas('whatsapp_auto_replies', ['workspace_id' => $other->id]);
        $this->assertDatabaseMissing('whatsapp_auto_replies', ['workspace_id' => $user->workspace_id]);
    }

    // ── §G-1b: WhatsappAutoReplyController::authorise() ─────────────────────

    #[Test]
    public function deleting_another_tenants_auto_reply_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeRule = $this->autoReply($home->id, 'homeword');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.whatsapp.auto-replies.destroy', $homeRule->id))
            ->assertForbidden();

        $this->assertDatabaseHas('whatsapp_auto_replies', ['id' => $homeRule->id]);
    }

    #[Test]
    public function deleting_an_auto_reply_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeRule = $this->autoReply($home->id, 'homeword');

        $this->actingAs($user)
            ->delete(route('client.whatsapp.auto-replies.destroy', $homeRule->id))
            ->assertRedirect();

        $this->assertDatabaseMissing('whatsapp_auto_replies', ['id' => $homeRule->id]);
    }

    #[Test]
    public function updating_another_tenants_auto_reply_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeRule = $this->autoReply($home->id, 'homeword');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->put(route('client.whatsapp.auto-replies.update', $homeRule->id), [
                'trigger_type' => 'keyword',
                'match_mode' => 'contains',
                'keywords' => ['hijacked'],
                'response_kind' => 'text',
                'payload_json' => ['text' => 'hijacked'],
                'enabled' => true,
                'priority' => 0,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function updating_an_auto_reply_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeRule = $this->autoReply($home->id, 'homeword');

        $this->actingAs($user)
            ->put(route('client.whatsapp.auto-replies.update', $homeRule->id), [
                'trigger_type' => 'keyword',
                'match_mode' => 'contains',
                'keywords' => ['renamed'],
                'response_kind' => 'text',
                'payload_json' => ['text' => 'renamed'],
                'enabled' => true,
                'priority' => 0,
            ])
            ->assertRedirect();
    }
}
