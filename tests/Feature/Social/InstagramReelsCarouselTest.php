<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Exceptions\PublishNotReadyException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ═══ REELS AND CAROUSEL — ONE LEVEL DEEPER THAN THE SINGLE-CONTAINER FIX ════
 *
 * A Reel reuses the single-container machinery InstagramContainerPollingTest
 * already proves, just with a wider poll budget — covered here mainly to
 * confirm the WIDER budget itself takes effect and stays inside the job
 * timeout, not to re-prove polling from scratch.
 *
 * A carousel is genuinely new: N child containers, persisted one at a time
 * into provider_upload_state, THEN a parent that reuses the exact
 * reusableContainerId/awaitFinished/publish path a single image uses. The
 * interesting tests are the crash-mid-creation ones — the exact idempotency
 * lesson InstagramContainerPollingTest proves for one container, applied to
 * several.
 *
 * ⚠️ NO LIVE CALLS. Every Graph endpoint is faked; Http::preventStrayRequests()
 * turns any un-faked call into a test failure rather than a real request.
 */
class InstagramReelsCarouselTest extends TestCase
{
    use RefreshDatabase;

    private const IG_USER = '17841400000000000';

    private const PUBLISHED = '18109341980032364';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'social.instagram.poll_interval_seconds' => 0,
            'social.instagram.poll_max_attempts' => 4,
            'social.instagram.video_poll_max_attempts' => 6,
        ]);

        Http::preventStrayRequests();
    }

    private function account(): SocialAccount
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        return SocialAccount::create([
            'workspace_id' => $workspace->id,
            'network' => 'instagram',
            'account_id' => self::IG_USER,
            'name' => 'Test IG',
            'access_token' => 'tok_123',
            'active' => true,
        ]);
    }

    /** @return array{0: SocialPost, 1: SocialAccount, 2: SocialPostAccount} */
    private function scenario(string $postType, ?string $mediaType, array $mediaUrls): array
    {
        $account = $this->account();

        $post = SocialPost::create([
            'workspace_id' => $account->workspace_id,
            'body' => 'hello',
            'media_urls' => $mediaUrls,
            'post_type' => $postType,
            'media_type' => $mediaType,
            'target_accounts' => [$account->id],
            'status' => 'publishing',
        ]);

        $link = SocialPostAccount::create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => 'pending',
        ]);

        return [$post, $account, $link];
    }

    private function driver(): InstagramSocialDriver
    {
        return new InstagramSocialDriver;
    }

    // ── Reels: the wider poll budget ────────────────────────────────────────

    #[Test]
    public function a_reel_publishes_with_media_type_reels_and_video_url(): void
    {
        [$post, $account] = $this->scenario('video', null, ['https://example.test/clip.mp4']);

        Http::fake([
            '*/media_publish' => Http::response(['id' => self::PUBLISHED], 200),
            '*/'.self::IG_USER.'/media' => Http::response(['id' => 'CONTAINER_REEL'], 200),
            '*/CONTAINER_REEL*' => Http::response(['status_code' => 'FINISHED'], 200),
        ]);

        $this->assertSame(self::PUBLISHED, $this->driver()->publish($account, $post->toArray()));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), self::IG_USER.'/media')
            && ! str_contains($r->url(), 'media_publish')
            && $r['media_type'] === 'REELS'
            && $r['video_url'] === 'https://example.test/clip.mp4'
            && $r['share_to_feed'] === 'true');
    }

    /**
     * ⚠️ THE POINT OF THE WIDER BUDGET. config/social.php sets
     * video_poll_max_attempts=6 here (vs image's 4) — a video that survives
     * 5 IN_PROGRESS polls (more than the IMAGE budget would tolerate) must
     * still finish, proving the video path is really using its own, wider
     * attempt count rather than silently sharing the image one.
     */
    #[Test]
    public function a_reel_gets_a_wider_poll_budget_than_an_image(): void
    {
        [$post, $account] = $this->scenario('video', null, ['https://example.test/clip.mp4']);

        Http::fake([
            '*/media_publish' => Http::response(['id' => self::PUBLISHED], 200),
            '*/'.self::IG_USER.'/media' => Http::response(['id' => 'CONTAINER_REEL'], 200),
            '*/CONTAINER_REEL*' => Http::sequence()
                ->push(['status_code' => 'IN_PROGRESS'], 200)
                ->push(['status_code' => 'IN_PROGRESS'], 200)
                ->push(['status_code' => 'IN_PROGRESS'], 200)
                ->push(['status_code' => 'IN_PROGRESS'], 200)
                ->push(['status_code' => 'IN_PROGRESS'], 200) // 5th IN_PROGRESS — would exhaust the image budget (4)
                ->push(['status_code' => 'FINISHED'], 200),
        ]);

        $this->assertSame(self::PUBLISHED, $this->driver()->publish($account, $post->toArray()));
    }

    /** An image, meanwhile, still exhausts at the NARROWER image budget. */
    #[Test]
    public function an_image_still_exhausts_at_the_narrower_budget(): void
    {
        [$post, $account, $link] = $this->scenario('image', 'single', ['https://example.test/a.jpg']);

        Http::fake([
            '*/'.self::IG_USER.'/media' => Http::response(['id' => 'CONTAINER_IMG'], 200),
            '*/CONTAINER_IMG*' => Http::response(['status_code' => 'IN_PROGRESS'], 200),
        ]);

        $this->expectException(PublishNotReadyException::class);
        try {
            $this->driver()->publish($account, $post->toArray());
        } finally {
            $this->assertSame('CONTAINER_IMG', $link->fresh()->provider_container_id);
        }
    }

    // ── Carousel: children then parent ──────────────────────────────────────

    #[Test]
    public function a_carousel_creates_one_child_per_image_then_one_parent(): void
    {
        [$post, $account, $link] = $this->scenario('image', 'carousel', [
            'https://example.test/a.jpg', 'https://example.test/b.jpg', 'https://example.test/c.jpg',
        ]);

        Http::fake([
            '*/media_publish' => Http::response(['id' => self::PUBLISHED], 200),
            '*/'.self::IG_USER.'/media' => Http::sequence()
                ->push(['id' => 'CHILD_1'], 200)
                ->push(['id' => 'CHILD_2'], 200)
                ->push(['id' => 'CHILD_3'], 200)
                ->push(['id' => 'PARENT'], 200),
            '*/PARENT*' => Http::response(['status_code' => 'FINISHED'], 200),
        ]);

        $this->assertSame(self::PUBLISHED, $this->driver()->publish($account, $post->toArray()));

        // Each child was created with is_carousel_item=true and its own image_url.
        foreach (['a', 'b', 'c'] as $i => $letter) {
            Http::assertSent(fn (Request $r) => str_contains($r->url(), self::IG_USER.'/media')
                && ($r['image_url'] ?? null) === "https://example.test/{$letter}.jpg"
                && ($r['is_carousel_item'] ?? null) === 'true');
        }

        // The parent references all three, in order, and is NOT itself a carousel item.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), self::IG_USER.'/media')
            && ($r['media_type'] ?? null) === 'CAROUSEL'
            && ($r['children'] ?? null) === 'CHILD_1,CHILD_2,CHILD_3');

        // Children are consumed into the parent — nothing left in provider_upload_state.
        $this->assertNull($link->fresh()->provider_upload_state);
        $this->assertNull($link->fresh()->provider_container_id);
    }

    /**
     * ⚠️ THE IDEMPOTENCY LESSON, ONE LEVEL DEEPER. Simulates a crash after
     * children 1-2 succeeded but before child 3 was attempted: the row already
     * has provider_upload_state with items [0, 1]. A fresh publish() call must
     * NOT recreate children 1-2 — only child 3, then the parent.
     */
    #[Test]
    public function a_retry_resumes_from_the_last_persisted_child_instead_of_recreating_them(): void
    {
        [$post, $account, $link] = $this->scenario('image', 'carousel', [
            'https://example.test/a.jpg', 'https://example.test/b.jpg', 'https://example.test/c.jpg',
        ]);

        // Simulate the crash: two children already confirmed and persisted.
        $link->update(['provider_upload_state' => [
            'phase' => 'creating_children',
            'items' => [
                ['index' => 0, 'media_url' => 'https://example.test/a.jpg', 'container_id' => 'CHILD_1'],
                ['index' => 1, 'media_url' => 'https://example.test/b.jpg', 'container_id' => 'CHILD_2'],
            ],
            'parent_id' => null,
            'meta' => [],
        ]]);

        Http::fake([
            '*/media_publish' => Http::response(['id' => self::PUBLISHED], 200),
            // Only ONE more child-creation call is stubbed to succeed with a
            // distinguishable id — if the driver tried to recreate child 1 or 2,
            // it would consume this same stub for the wrong item.
            '*/'.self::IG_USER.'/media' => Http::sequence()
                ->push(['id' => 'CHILD_3'], 200)
                ->push(['id' => 'PARENT'], 200),
            '*/PARENT*' => Http::response(['status_code' => 'FINISHED'], 200),
        ]);

        $this->assertSame(self::PUBLISHED, $this->driver()->publish($account, $post->toArray()));

        // Exactly ONE child-creation call this time (child 3), not three.
        $childCreates = 0;
        Http::recorded(function (Request $r) use (&$childCreates) {
            if (str_contains($r->url(), self::IG_USER.'/media')
                && $r->method() === 'POST'
                && ($r['is_carousel_item'] ?? null) === 'true') {
                $childCreates++;
            }

            return true;
        });
        $this->assertSame(1, $childCreates, 'a resumed carousel recreated children that already existed');

        Http::assertSent(fn (Request $r) => ($r['children'] ?? null) === 'CHILD_1,CHILD_2,CHILD_3');
    }

    /**
     * ⚠️ FAILS THE WHOLE CAROUSEL, NAMES THE ITEM. Child 3 of 5 is rejected by
     * Meta. The two already-succeeded children stay recorded (so a retry does
     * not recreate them — proven separately above), but nothing is published:
     * a carousel missing an item must never go out silently.
     */
    #[Test]
    public function a_failed_child_fails_the_whole_carousel_and_names_which_item(): void
    {
        [$post, $account, $link] = $this->scenario('image', 'carousel', [
            'https://example.test/a.jpg', 'https://example.test/b.jpg', 'https://example.test/BAD.jpg',
            'https://example.test/d.jpg', 'https://example.test/e.jpg',
        ]);

        Http::fake([
            '*/'.self::IG_USER.'/media' => Http::sequence()
                ->push(['id' => 'CHILD_1'], 200)
                ->push(['id' => 'CHILD_2'], 200)
                ->push(['error' => ['message' => 'Unsupported image format']], 400), // child 3 fails
        ]);

        try {
            $this->driver()->publish($account, $post->toArray());
            $this->fail('a rejected child did not fail the carousel');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('item 3 of 5', $e->getMessage());
            $this->assertStringContainsString('BAD.jpg', $e->getMessage());
        }

        // Never reached media_publish — nothing was published with a missing item.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'media_publish'));

        // The two successful children ARE persisted, for a retry to resume from.
        $items = $link->fresh()->provider_upload_state['items'] ?? [];
        $this->assertCount(2, $items, 'successful children were not preserved after the failure');
        $this->assertSame('CHILD_1', $items[0]['container_id']);
        $this->assertSame('CHILD_2', $items[1]['container_id']);
    }

    #[Test]
    public function a_carousel_below_the_minimum_is_rejected_by_the_driver_too(): void
    {
        [$post, $account] = $this->scenario('image', 'carousel', ['https://example.test/only-one.jpg']);

        Http::fake(['*' => Http::response([], 500)]); // nothing should be called

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('between 2 and');

        $this->driver()->publish($account, $post->toArray());
    }

    #[Test]
    public function a_carousel_above_the_maximum_is_rejected_by_the_driver_too(): void
    {
        $urls = array_map(fn ($i) => "https://example.test/{$i}.jpg", range(1, 11));
        [$post, $account] = $this->scenario('image', 'carousel', $urls);

        Http::fake(['*' => Http::response([], 500)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('between 2 and 10');

        $this->driver()->publish($account, $post->toArray());
    }

    /** A finished parent is reused exactly like a finished single-image container. */
    #[Test]
    public function a_stored_parent_container_is_resumed_without_recreating_children(): void
    {
        [$post, $account, $link] = $this->scenario('image', 'carousel', [
            'https://example.test/a.jpg', 'https://example.test/b.jpg',
        ]);

        $link->update([
            'provider_container_id' => 'PARENT_FROM_BEFORE',
            'container_created_at' => now(),
        ]);

        Http::fake([
            '*/media_publish' => Http::response(['id' => self::PUBLISHED], 200),
            '*/PARENT_FROM_BEFORE*' => Http::response(['status_code' => 'FINISHED'], 200),
        ]);

        $this->assertSame(self::PUBLISHED, $this->driver()->publish($account, $post->toArray()));

        // ⚠️ Must exclude media_publish explicitly — its URL is
        // ".../{IG_USER}/media_publish", which CONTAINS ".../{IG_USER}/media"
        // as a substring. An assertion that only checked str_contains() would
        // wrongly flag the (expected) publish POST as an (unexpected) new
        // container creation.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), self::IG_USER.'/media')
            && ! str_contains($r->url(), 'media_publish')
            && $r->method() === 'POST');
    }
}
