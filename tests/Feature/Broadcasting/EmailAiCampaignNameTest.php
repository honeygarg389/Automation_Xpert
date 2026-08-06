<?php

namespace Tests\Feature\Broadcasting;

use App\Modules\AI\Models\AiProviderConfig;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-003 class, found opportunistically during the 1c Broadcasting commit.
 * Same root cause as BUG-001: `validate()` OMITS an absent `nullable` key from
 * the array it returns rather than including it as null, so
 *
 *     $campaignCtx = $validated['campaign_name'] ? … : '';
 *
 * threw "Undefined array key" — a 500 — on any generate request that did not
 * send campaign_name at all.
 *
 * NOT reachable from the shipped UI: AiGeneratePanel defaults campaignName to
 * '' and always sends the key, so validate() always returns it. Recorded and
 * fixed anyway — the guard is free and the next caller finds a 500 otherwise.
 *
 * These tests also cover EmailAiController's workspace resolution, which has no
 * abort of any kind: the resolution alone decides whose LLM credentials are
 * loaded and whose AI spend is charged.
 */
class EmailAiCampaignNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::flush();

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => "SUBJECT: Test subject\nBODY:\n<p>Hello there.</p>"]]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);
    }

    protected function tearDown(): void
    {
        WorkspaceContext::flush();
        parent::tearDown();
    }

    private function giveWorkspaceAnLlm(int $workspaceId): void
    {
        AiProviderConfig::create([
            'workspace_id' => $workspaceId,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);
    }

    #[Test]
    public function generating_an_email_without_a_campaign_name_does_not_500(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $this->giveWorkspaceAnLlm($home->id);

        $this->actingAs($user)
            ->postJson(route('client.campaigns.generate-email'), [
                'prompt' => 'Write a welcome email.',
            ])
            ->assertOk()
            ->assertJsonStructure(['subject', 'body']);
    }

    /**
     * The counterpart, so the fix cannot pass by simply discarding the name:
     * when a campaign_name IS sent it must still reach the model's prompt.
     */
    #[Test]
    public function a_supplied_campaign_name_still_reaches_the_prompt(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $this->giveWorkspaceAnLlm($home->id);

        $this->actingAs($user)
            ->postJson(route('client.campaigns.generate-email'), [
                'prompt' => 'Write a welcome email.',
                'campaign_name' => 'Spring Launch',
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->body(), 'Spring Launch');
        });
    }

    /**
     * The third shape the endpoint can receive. NOT load-bearing, and recorded
     * as such: an explicitly-null campaign_name IS returned by validate(), so
     * the array key exists and this passes with or without the fix. Only the
     * absent-key case above reproduces the 500. Kept because it pins the
     * distinction — absent and null are different here, and that is precisely
     * what makes this bug class easy to "fix" against the wrong shape.
     */
    #[Test]
    public function generating_an_email_with_a_null_campaign_name_does_not_500(): void
    {
        ['user' => $user, 'home' => $home] = $this->createTwoWorkspaceUser();
        $this->giveWorkspaceAnLlm($home->id);

        $this->actingAs($user)
            ->postJson(route('client.campaigns.generate-email'), [
                'prompt' => 'Write a welcome email.',
                'campaign_name' => null,
            ])
            ->assertOk();
    }

    // ── §G-1b: the resolution decides whose LLM credentials are spent ───────

    /**
     * Only the SWITCHED workspace has a provider. If the controller resolved the
     * home workspace it would find none and return the 422 below.
     */
    #[Test]
    public function the_ai_endpoint_uses_the_switched_workspaces_llm_provider(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->giveWorkspaceAnLlm($other->id);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $other->id])
            ->postJson(route('client.campaigns.generate-email'), [
                'prompt' => 'Write a welcome email.',
            ])
            ->assertOk();
    }

    /**
     * NEGATIVE CONTROL for the above. Same fixture, no switch: the home
     * workspace has no provider, so the request must fail with the "not
     * configured" 422. Without this, the test above would pass even if the
     * controller ignored the workspace entirely.
     */
    #[Test]
    public function without_switching_the_home_workspace_has_no_provider(): void
    {
        ['user' => $user, 'other' => $other] = $this->createTwoWorkspaceUser();
        $this->giveWorkspaceAnLlm($other->id);

        $this->actingAs($user)
            ->postJson(route('client.campaigns.generate-email'), [
                'prompt' => 'Write a welcome email.',
            ])
            ->assertStatus(422)
            ->assertJson(['error' => 'No AI provider configured. Set one up in AI → Providers.']);
    }
}
