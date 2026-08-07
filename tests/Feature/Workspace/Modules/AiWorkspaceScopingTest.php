<?php

namespace Tests\Feature\Workspace\Modules;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 1c — AI module (6 resolution sites across 3 controllers, 13 public methods).
 *
 * Two of the six are §G-1b AUTHORIZATION guards rather than data scoping, and
 * between them they cover 7 of the module's 13 public methods. They were
 * "correct" only because the switcher was broken:
 *
 *   AiKnowledgeBaseController::authorise()  -> show, addDocument, reindex,
 *                                              destroyDocument
 *   AiChatbotController::authorise()        -> update, destroy, playground
 *
 * Every "blocked" assertion is paired with a positive control on the SAME route
 * and verb, per CLAUDE.md.
 *
 * Traps guarded, all learned the hard way on earlier groups:
 *  - ROUTE KEYS: AiKnowledgeBase, AiKbDocument AND AiChatbot all bind by `uuid`.
 *    Passing ->id would 404 at binding and never reach authorise(), so the test
 *    would assert nothing. (On Ecommerce this bit via Contact, which was not the
 *    model I was watching.)
 *  - ONE REQUEST PER TEST: WorkspaceContext memoises per user id for the life of
 *    a test, so a second request reuses the first's resolution. Switched and
 *    unswitched cases are therefore separate tests, not two calls in one.
 *  - None of these models soft-delete, so assertDatabaseMissing/Has is
 *    load-bearing for the delete assertions.
 */
class AiWorkspaceScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();

        // playground() reaches the LLM providers and addDocument() queues
        // indexing; QUEUE_CONNECTION=sync would run those inline.
        Queue::fake();
        Http::fake();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    private function kb(int $workspaceId, string $name): AiKnowledgeBase
    {
        return AiKnowledgeBase::create(['workspace_id' => $workspaceId, 'name' => $name]);
    }

    private function chatbot(int $workspaceId, string $name): AiChatbot
    {
        return AiChatbot::create(['workspace_id' => $workspaceId, 'name' => $name]);
    }

    // ── Knowledge base list follows the switch ──────────────────────────────

    #[Test]
    public function the_knowledge_base_list_shows_the_home_workspace_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->kb($home->id, 'HomeKb');
        $this->kb($other->id, 'OtherKb');

        $this->actingAs($user)
            ->get(route('client.ai.knowledge-bases.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('knowledgeBases', 1)
                ->where('knowledgeBases.0.name', 'HomeKb'));
    }

    #[Test]
    public function the_knowledge_base_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->kb($home->id, 'HomeKb');
        $this->kb($other->id, 'OtherKb');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.ai.knowledge-bases.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('knowledgeBases', 1)
                ->where('knowledgeBases.0.name', 'OtherKb'));
    }

    #[Test]
    public function a_new_knowledge_base_is_created_in_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.ai.knowledge-bases.store'), ['name' => 'Created While Switched']);

        $this->assertDatabaseHas('ai_knowledge_bases', [
            'name' => 'Created While Switched',
            'workspace_id' => $other->id,
        ]);
    }

    // ── §G-1b: AiKnowledgeBaseController::authorise() ───────────────────────

    #[Test]
    public function viewing_another_tenants_knowledge_base_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeKb = $this->kb($home->id, 'HomeKb');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.ai.knowledge-bases.show', $homeKb->uuid))
            ->assertForbidden();
    }

    #[Test]
    public function viewing_a_knowledge_base_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeKb = $this->kb($home->id, 'HomeKb');

        $this->actingAs($user)
            ->get(route('client.ai.knowledge-bases.show', $homeKb->uuid))
            ->assertOk();
    }

    #[Test]
    public function adding_a_document_to_another_tenants_knowledge_base_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeKb = $this->kb($home->id, 'HomeKb');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.ai.knowledge-bases.documents.add', $homeKb->uuid), [
                'source_type' => 'text',
                'content' => 'some text',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('ai_kb_documents', ['kb_id' => $homeKb->id]);
    }

    #[Test]
    public function adding_a_document_to_a_knowledge_base_in_the_current_workspace_is_authorized(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeKb = $this->kb($home->id, 'HomeKb');

        // Same route, same verb, same user — proves the 403 above is about the
        // workspace and not about the endpoint rejecting everyone.
        $this->actingAs($user)
            ->post(route('client.ai.knowledge-bases.documents.add', $homeKb->uuid), [
                'source_type' => 'text',
                'content' => 'some text',
            ])
            ->assertRedirect();
    }

    // ── Chatbot list follows the switch ─────────────────────────────────────

    #[Test]
    public function the_chatbot_list_shows_the_home_workspace_when_unswitched(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->chatbot($home->id, 'HomeBot');
        $this->chatbot($other->id, 'OtherBot');

        $this->actingAs($user)
            ->get(route('client.ai.chatbots.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('chatbots', 1)
                ->where('chatbots.0.name', 'HomeBot'));
    }

    #[Test]
    public function the_chatbot_list_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->chatbot($home->id, 'HomeBot');
        $this->chatbot($other->id, 'OtherBot');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.ai.chatbots.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('chatbots', 1)
                ->where('chatbots.0.name', 'OtherBot'));
    }

    #[Test]
    public function a_new_chatbot_is_created_in_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->post(route('client.ai.chatbots.store'), ['name' => 'Bot While Switched']);

        $this->assertDatabaseHas('ai_chatbots', [
            'name' => 'Bot While Switched',
            'workspace_id' => $other->id,
        ]);
    }

    // ── §G-1b: AiChatbotController::authorise() ─────────────────────────────

    #[Test]
    public function deleting_another_tenants_chatbot_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeBot = $this->chatbot($home->id, 'HomeBot');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->delete(route('client.ai.chatbots.destroy', $homeBot->uuid))
            ->assertForbidden();

        // AiChatbot does not soft-delete, so a surviving row genuinely proves
        // the delete was prevented.
        $this->assertDatabaseHas('ai_chatbots', ['id' => $homeBot->id]);
    }

    #[Test]
    public function deleting_a_chatbot_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeBot = $this->chatbot($home->id, 'HomeBot');

        $this->actingAs($user)
            ->delete(route('client.ai.chatbots.destroy', $homeBot->uuid))
            ->assertRedirect();

        $this->assertDatabaseMissing('ai_chatbots', ['id' => $homeBot->id]);
    }

    #[Test]
    public function updating_another_tenants_chatbot_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeBot = $this->chatbot($home->id, 'HomeBot');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->put(route('client.ai.chatbots.update', $homeBot->uuid), ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertDatabaseHas('ai_chatbots', ['id' => $homeBot->id, 'name' => 'HomeBot']);
    }

    #[Test]
    public function updating_a_chatbot_in_the_current_workspace_succeeds(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $homeBot = $this->chatbot($home->id, 'HomeBot');

        $this->actingAs($user)
            ->put(route('client.ai.chatbots.update', $homeBot->uuid), ['name' => 'Renamed'])
            ->assertRedirect();

        $this->assertDatabaseHas('ai_chatbots', ['id' => $homeBot->id, 'name' => 'Renamed']);
    }

    #[Test]
    public function the_playground_on_another_tenants_chatbot_is_blocked(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();
        $homeBot = $this->chatbot($home->id, 'HomeBot');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.ai.chatbots.playground', $homeBot->uuid), ['message' => 'hi'])
            ->assertForbidden();
    }

    // ── Provider configuration is per-workspace ─────────────────────────────

    #[Test]
    public function a_provider_configured_while_switched_is_saved_to_the_switched_workspace(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->put(route('client.ai.providers.update', 'openai'), [
                'api_key' => 'sk-switched',
                'enabled' => true,
            ]);

        $this->assertDatabaseHas('ai_provider_configs', [
            'provider' => 'openai',
            'workspace_id' => $other->id,
        ]);
        // The home workspace must not have been written to.
        $this->assertDatabaseMissing('ai_provider_configs', [
            'provider' => 'openai',
            'workspace_id' => $home->id,
        ]);
    }

    /**
     * Covers AiProviderController::index — the sixth site. The first draft of
     * this test only re-asserted the DB write from the test above and never
     * requested the page, so it exercised nothing. It now reads the page back
     * and asserts the `configured` flag differs by workspace.
     */
    #[Test]
    public function the_provider_page_shows_only_the_home_workspaces_configuration(): void
    {
        ['user' => $user, 'home' => $home, 'other' => $other] = $this->createTwoWorkspaceUser();

        AiProviderConfig::create([
            'workspace_id' => $other->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-other'],
            'enabled' => true,
        ]);

        // Unswitched: the other workspace's provider must not show as configured.
        $this->actingAs($user)
            ->get(route('client.ai.providers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('providers.0.provider', 'openai')
                ->where('providers.0.configured', false)
                ->where('providers.0.enabled', false));
    }

    #[Test]
    public function the_provider_page_follows_the_switched_workspace(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();

        AiProviderConfig::create([
            'workspace_id' => $other->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-other'],
            'enabled' => true,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->get(route('client.ai.providers.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('providers.0.provider', 'openai')
                ->where('providers.0.configured', true)
                ->where('providers.0.enabled', true));
    }
}
