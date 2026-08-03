<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression cover for BUG-001.
 *
 * SocialPostController validates scheduled_at as `nullable`. Laravel's
 * validate() OMITS an absent nullable key from its result rather than returning
 * it as null — so a request that simply does not send scheduled_at (the normal
 * case for an unscheduled post) hit `$validated['scheduled_at']` on an
 * undefined key and 500'd.
 *
 * Four unguarded reads existed: three in store() (lines ~175, ~178, ~187) and
 * one in update() (~231). Both methods now normalise the key once before use.
 *
 * See docs/found-bugs.md BUG-001.
 */
class SocialPostScheduledAtTest extends TestCase
{
    use RefreshDatabase;

    private function account(int $workspaceId): SocialAccount
    {
        return SocialAccount::create([
            'workspace_id' => $workspaceId,
            'network' => 'facebook',
            'account_id' => 'acct_regression',
            'name' => 'Regression Account',
            'access_token' => 'test-token',
            'active' => true,
        ]);
    }

    // ── store() ─────────────────────────────────────────────────────────────

    /** The bug: omitting the optional field entirely used to 500. */
    #[Test]
    public function creating_a_post_without_scheduled_at_succeeds(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->account($workspace->id);

        $this->actingAs($user)
            ->post(route('client.social.posts.store'), [
                'body' => 'Unscheduled post',
                'target_accounts' => [$account->id],
                // scheduled_at deliberately absent — not empty string, ABSENT.
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('social_media_posts', [
            'workspace_id' => $workspace->id,
            'body' => 'Unscheduled post',
        ]);
    }

    /**
     * COUNTERPART: a valid future scheduled_at must still schedule correctly.
     * Without this, the fix could have been "always treat it as null" and the
     * test above would still pass.
     */
    #[Test]
    public function creating_a_post_with_a_future_scheduled_at_still_schedules(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->account($workspace->id);

        $this->actingAs($user)
            ->post(route('client.social.posts.store'), [
                'body' => 'Scheduled post',
                'target_accounts' => [$account->id],
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $post = SocialPost::where('body', 'Scheduled post')->firstOrFail();

        $this->assertSame('scheduled', $post->status);
        $this->assertNotNull($post->scheduled_at);
    }

    /** An unscheduled post is queued for immediate publishing, not left as a draft. */
    #[Test]
    public function an_unscheduled_post_is_queued_for_publishing(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->account($workspace->id);

        $this->actingAs($user)
            ->post(route('client.social.posts.store'), [
                'body' => 'Publish now',
                'target_accounts' => [$account->id],
            ])
            ->assertRedirect();

        $post = SocialPost::where('body', 'Publish now')->firstOrFail();
        $this->assertSame('publishing', $post->status);
    }

    // ── update() ────────────────────────────────────────────────────────────

    /** The originally reported case. */
    #[Test]
    public function updating_a_post_without_scheduled_at_succeeds(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->account($workspace->id);

        $post = SocialPost::create([
            'workspace_id' => $workspace->id,
            'body' => 'Original',
            'status' => 'draft',
            'ai_generated' => false,
        ]);

        $this->actingAs($user)
            ->put(route('client.social.posts.update', $post), [
                'body' => 'Edited without a schedule',
                'target_accounts' => [$account->id],
                // scheduled_at absent
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('social_media_posts', [
            'id' => $post->id,
            'body' => 'Edited without a schedule',
            'status' => 'draft',
        ]);
    }

    /** COUNTERPART for update(): a valid future schedule still applies. */
    #[Test]
    public function updating_a_post_with_a_future_scheduled_at_still_schedules(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->account($workspace->id);

        $post = SocialPost::create([
            'workspace_id' => $workspace->id,
            'body' => 'Original',
            'status' => 'draft',
            'ai_generated' => false,
        ]);

        $this->actingAs($user)
            ->put(route('client.social.posts.update', $post), [
                'body' => 'Edited with a schedule',
                'target_accounts' => [$account->id],
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $post->refresh();
        $this->assertSame('scheduled', $post->status);
        $this->assertNotNull($post->scheduled_at);
    }

    /** A past scheduled_at is still rejected — the fix must not weaken validation. */
    #[Test]
    public function a_past_scheduled_at_is_still_rejected(): void
    {
        Queue::fake();
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $account = $this->account($workspace->id);

        $this->actingAs($user)
            ->post(route('client.social.posts.store'), [
                'body' => 'Past schedule',
                'target_accounts' => [$account->id],
                'scheduled_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertSessionHasErrors('scheduled_at');

        $this->assertDatabaseMissing('social_media_posts', ['body' => 'Past schedule']);
    }
}
