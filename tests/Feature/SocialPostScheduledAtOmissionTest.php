<?php

namespace Tests\Feature;

use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BUG-001 — SocialPostController 500s when `scheduled_at` is omitted.
 *
 * `scheduled_at` validates as `nullable`. Laravel's validate() OMITS an absent
 * nullable key from its returned array rather than returning it as null, so
 * every unguarded `$validated['scheduled_at']` read threw "Undefined array key"
 * — a 500 — for the ordinary case of a post that simply is not scheduled.
 *
 * Four reads were affected: three in store(), one in update().
 *
 * Each "omitted" test below is paired with a positive control sending a real
 * future timestamp, so a fix that merely made the scheduled branch unreachable
 * would fail rather than look green.
 */
class SocialPostScheduledAtOmissionTest extends TestCase
{
    use RefreshDatabase;

    /** No SocialPost/SocialAccount factory exists; built inline against the schema. */
    private function makeAccount(int $workspaceId): SocialAccount
    {
        return SocialAccount::create([
            'workspace_id' => $workspaceId,
            'network' => 'facebook',
            'account_id' => 'acct_regression',
            'name' => 'Regression Page',
            'access_token' => 'test-token',
            'active' => true,
        ]);
    }

    private function makeDraft(int $workspaceId): SocialPost
    {
        return SocialPost::create([
            'workspace_id' => $workspaceId,
            'title' => 'Existing draft',
            'body' => 'Original body',
            'status' => 'draft',
            'ai_generated' => false,
        ]);
    }

    // ── update() ────────────────────────────────────────────────────────────

    #[Test]
    public function updating_a_post_without_scheduled_at_succeeds_instead_of_500ing(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->makeAccount($workspace->id);
        $post = $this->makeDraft($workspace->id);

        // The payload a normal "edit an unscheduled post" form submits: no
        // scheduled_at key at all — not an empty string, absent.
        $response = $this->actingAs($user)->put(route('client.social.posts.update', $post), [
            'body' => 'Updated body',
            'target_accounts' => [$account->id],
        ]);

        $response->assertRedirect(route('client.social.posts.index'));
        $response->assertSessionHas('success');
        $response->assertSessionHasNoErrors();

        $post->refresh();
        $this->assertSame('Updated body', $post->body);
        $this->assertSame('draft', $post->status);
        $this->assertNull($post->scheduled_at);
    }

    /**
     * Positive control for the test above: the same route and verb with a real
     * future timestamp must still take the scheduling branch. Without this, a
     * "fix" that hard-coded 'draft' would pass the omission test.
     */
    #[Test]
    public function updating_a_post_with_a_future_scheduled_at_still_schedules_it(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->makeAccount($workspace->id);
        $post = $this->makeDraft($workspace->id);

        $scheduledAt = now()->addDay()->startOfMinute();

        $response = $this->actingAs($user)->put(route('client.social.posts.update', $post), [
            'body' => 'Updated body',
            'target_accounts' => [$account->id],
            'scheduled_at' => $scheduledAt->toIso8601String(),
        ]);

        $response->assertRedirect(route('client.social.posts.index'));
        $response->assertSessionHasNoErrors();

        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertNotNull($post->scheduled_at);
        $this->assertSame(
            $scheduledAt->utc()->format('Y-m-d H:i:s'),
            $post->scheduled_at->utc()->format('Y-m-d H:i:s')
        );
    }

    /** A past timestamp must still be rejected — the guard above it is unchanged. */
    #[Test]
    public function updating_a_post_with_a_past_scheduled_at_is_still_rejected(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->makeAccount($workspace->id);
        $post = $this->makeDraft($workspace->id);

        $this->actingAs($user)->put(route('client.social.posts.update', $post), [
            'body' => 'Updated body',
            'target_accounts' => [$account->id],
            'scheduled_at' => now()->subDay()->toIso8601String(),
        ])->assertSessionHasErrors('scheduled_at');

        $this->assertSame('Original body', $post->refresh()->body);
    }

    // ── store() — same defect, three reads ──────────────────────────────────

    #[Test]
    public function storing_a_post_without_scheduled_at_succeeds_instead_of_500ing(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->makeAccount($workspace->id);

        $response = $this->actingAs($user)->post(route('client.social.posts.store'), [
            'body' => 'Publish me now',
            'target_accounts' => [$account->id],
        ]);

        $response->assertSessionHasNoErrors();

        $post = SocialPost::where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('publishing', $post->status);
        $this->assertNull($post->scheduled_at);
        Queue::assertPushed(PublishSocialPostJob::class);
    }

    /** Positive control: a future timestamp must schedule, not publish immediately. */
    #[Test]
    public function storing_a_post_with_a_future_scheduled_at_schedules_it(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->makeAccount($workspace->id);

        $scheduledAt = now()->addDay()->startOfMinute();

        $response = $this->actingAs($user)->post(route('client.social.posts.store'), [
            'body' => 'Publish me later',
            'target_accounts' => [$account->id],
            'scheduled_at' => $scheduledAt->toIso8601String(),
        ]);

        $response->assertSessionHasNoErrors();

        $post = SocialPost::where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('scheduled', $post->status);
        $this->assertNotNull($post->scheduled_at);
        Queue::assertNotPushed(PublishSocialPostJob::class);
    }
}
