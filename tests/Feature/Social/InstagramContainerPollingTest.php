<?php

namespace Tests\Feature\Social;

use App\Modules\Social\Exceptions\PublishNotReadyException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use App\Modules\Social\Services\SocialPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ═══ INSTAGRAM'S TWO-CALL PUBLISH, AND THE GAP BETWEEN THE CALLS ════════════
 *
 * The driver used to create a media container and immediately publish it. Meta
 * processes the image asynchronously, so the publish raced the processing — and
 * because the container id was discarded, every retry created a NEW container
 * and raced it again.
 *
 * ⚠️ THE ASSERTIONS ARE ON THE OUTBOUND REQUESTS, not on return values. The old
 * code and the new code both return a post id on the happy path; what separates
 * them is how many containers were created and whether the status was ever
 * checked. Only the request log distinguishes "resumed the upload" from "started
 * a third one".
 *
 * ⚠️ NO LIVE CALLS. Every Graph endpoint is faked. This publishes to a real
 * Instagram account in production, so the suite must never reach the network.
 */
class InstagramContainerPollingTest extends TestCase
{
    use RefreshDatabase;

    private const IG_USER = '17841400000000000';

    private const CONTAINER = '17999000000000001';

    private const PUBLISHED = '18109341980032363';

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠️ NO REAL SLEEPING. The driver waits 3s between polls; at 15 attempts
        // the exhaustion tests alone would stall the suite for well over two
        // minutes. Zero interval exercises the identical branch, just instantly.
        config([
            'social.instagram.poll_interval_seconds' => 0,
            'social.instagram.poll_max_attempts' => 4,
        ]);

        // Any un-faked Graph call is a bug in the test, not a network round trip
        // — this publishes to a real account in production.
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
    private function scenario(): array
    {
        $account = $this->account();

        $post = SocialPost::create([
            'workspace_id' => $account->workspace_id,
            'body' => 'hello',
            'media_urls' => ['https://example.test/a.jpg'],
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

    private function countCreates(): int
    {
        $n = 0;
        Http::recorded(function (Request $r) use (&$n) {
            if (str_contains($r->url(), '/media') && ! str_contains($r->url(), '/media_publish') && $r->method() === 'POST') {
                $n++;
            }

            return true;
        });

        return $n;
    }

    // ── Happy paths ───────────────────────────────────────────────────────

    #[Test]
    public function it_publishes_immediately_when_the_container_is_finished_on_the_first_poll(): void
    {
        [$post, $account, $link] = $this->scenario();

        Http::fake([
            '*/media_publish' => Http::response(['id' => self::PUBLISHED], 200),
            '*/'.self::IG_USER.'/media' => Http::response(['id' => self::CONTAINER], 200),
            '*/'.self::CONTAINER.'*' => Http::response(['status_code' => 'FINISHED'], 200),
        ]);

        $id = $this->driver()->publish($account, $post->toArray());

        $this->assertSame(self::PUBLISHED, $id);
        $this->assertSame(1, $this->countCreates(), 'more than one container was created');

        // The container is forgotten once it becomes a post — it can never be reused.
        $this->assertNull($link->fresh()->provider_container_id);
    }

    #[Test]
    public function it_keeps_polling_while_in_progress_then_publishes(): void
    {
        [$post, $account] = $this->scenario();

        Http::fake([
            '*/media_publish' => Http::response(['id' => self::PUBLISHED], 200),
            '*/'.self::IG_USER.'/media' => Http::response(['id' => self::CONTAINER], 200),
            '*/'.self::CONTAINER.'*' => Http::sequence()
                ->push(['status_code' => 'IN_PROGRESS'], 200)
                ->push(['status_code' => 'IN_PROGRESS'], 200)
                ->push(['status_code' => 'FINISHED'], 200),
        ]);

        $this->assertSame(self::PUBLISHED, $this->driver()->publish($account, $post->toArray()));
        $this->assertSame(1, $this->countCreates());
    }

    // ── Terminal failures ─────────────────────────────────────────────────

    /** ⚠️ ERROR must stop at once, not burn the whole polling window. */
    #[Test]
    public function an_error_status_fails_immediately_and_does_not_keep_polling(): void
    {
        [$post, $account, $link] = $this->scenario();

        Http::fake([
            '*/'.self::IG_USER.'/media' => Http::response(['id' => self::CONTAINER], 200),
            '*/'.self::CONTAINER.'*' => Http::response([
                'status_code' => 'ERROR',
                'status' => 'Media download failed',
            ], 200),
        ]);

        try {
            $this->driver()->publish($account, $post->toArray());
            $this->fail('an ERROR container was treated as publishable');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Media download failed', $e->getMessage());
            $this->assertNotInstanceOf(PublishNotReadyException::class, $e,
                'an ERROR is terminal and must not be retried as "not ready"');
        }

        // Exactly one status check — it stopped on the first ERROR.
        $polls = 0;
        Http::recorded(function (Request $r) use (&$polls) {
            if ($r->method() === 'GET' && str_contains($r->url(), self::CONTAINER)) {
                $polls++;
            }

            return true;
        });
        $this->assertSame(1, $polls, 'it kept polling a container Meta had already rejected');

        // Never published.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'media_publish'));
        $this->assertNull($link->fresh()->provider_container_id, 'a rejected container was kept for reuse');
    }

    #[Test]
    public function an_expired_status_fails_immediately_with_a_clear_message(): void
    {
        [$post, $account, $link] = $this->scenario();

        Http::fake([
            '*/'.self::IG_USER.'/media' => Http::response(['id' => self::CONTAINER], 200),
            '*/'.self::CONTAINER.'*' => Http::response(['status_code' => 'EXPIRED'], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired');

        try {
            $this->driver()->publish($account, $post->toArray());
        } finally {
            $this->assertNull($link->fresh()->provider_container_id);
        }
    }

    // ── Exhaustion → retryable, container KEPT ────────────────────────────

    /**
     * ⚠️ The container must SURVIVE exhaustion. That is what makes the retry a
     * resumption instead of a third race against the same delay.
     */
    #[Test]
    public function exhausting_the_window_throws_a_retryable_exception_and_keeps_the_container(): void
    {
        [$post, $account, $link] = $this->scenario();

        Http::fake([
            '*/'.self::IG_USER.'/media' => Http::response(['id' => self::CONTAINER], 200),
            '*/'.self::CONTAINER.'*' => Http::response(['status_code' => 'IN_PROGRESS'], 200),
        ]);

        try {
            $this->driver()->publish($account, $post->toArray());
            $this->fail('exhausting the poll window did not raise');
        } catch (PublishNotReadyException $e) {
            $this->assertStringContainsString('still processing', $e->getMessage());
        }

        $this->assertSame(self::CONTAINER, $link->fresh()->provider_container_id,
            'the container was discarded, so the retry would create a second one');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'media_publish'));
    }

    /** The publisher must let that one exception through to the job. */
    #[Test]
    public function the_publisher_rethrows_not_ready_so_the_job_retries(): void
    {
        [$post, $account, $link] = $this->scenario();

        Http::fake([
            '*/'.self::IG_USER.'/media' => Http::response(['id' => self::CONTAINER], 200),
            '*/'.self::CONTAINER.'*' => Http::response(['status_code' => 'IN_PROGRESS'], 200),
        ]);

        $this->expectException(PublishNotReadyException::class);

        try {
            app(SocialPublisher::class)->publish($post->fresh());
        } finally {
            // Neither terminal status is true yet.
            $this->assertSame('pending', $link->fresh()->status,
                'a still-processing account was marked failed, discarding accepted work');
            $this->assertNotSame('failed', $post->fresh()->status);
        }
    }

    // ── Idempotency across attempts ───────────────────────────────────────

    /**
     * ⚠️ THE BUG THIS FEATURE EXISTS FOR. Two attempts, ONE container.
     *
     * ⚠️ ONE fake with a SEQUENCE, not two Http::fake() calls. Http::fake()
     * MERGES stubs rather than replacing them, so a second call re-stubbing the
     * status URL loses to the first — attempt 2 would keep seeing IN_PROGRESS
     * and the test would fail for a reason that has nothing to do with the code.
     * The sequence spans both attempts: exhaust the window, then finish.
     */
    #[Test]
    public function a_retry_reuses_the_stored_container_instead_of_creating_a_second(): void
    {
        [$post, $account, $link] = $this->scenario();

        // poll_max_attempts is 4 in setUp, so four IN_PROGRESS exhausts attempt 1.
        Http::fake([
            '*/media_publish' => Http::response(['id' => self::PUBLISHED], 200),
            '*/'.self::IG_USER.'/media' => Http::response(['id' => self::CONTAINER], 200),
            '*/'.self::CONTAINER.'*' => Http::sequence()
                ->push(['status_code' => 'IN_PROGRESS'], 200)
                ->push(['status_code' => 'IN_PROGRESS'], 200)
                ->push(['status_code' => 'IN_PROGRESS'], 200)
                ->push(['status_code' => 'IN_PROGRESS'], 200)
                ->push(['status_code' => 'FINISHED'], 200),
        ]);

        // Attempt 1: container created, still processing, deferred.
        try {
            $this->driver()->publish($account, $post->toArray());
            $this->fail('attempt 1 should have deferred');
        } catch (PublishNotReadyException) {
            // expected
        }

        $this->assertSame(1, $this->countCreates(), 'attempt 1 created more than one container');
        $this->assertSame(self::CONTAINER, $link->fresh()->provider_container_id);

        // Attempt 2: the SAME container, now finished.
        $this->assertSame(self::PUBLISHED, $this->driver()->publish($account, $post->fresh()->toArray()));

        $this->assertSame(1, $this->countCreates(),
            'the retry created a SECOND container instead of resuming the stored one');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'media_publish')
            && $r['creation_id'] === self::CONTAINER);
    }

    /** An expired stored id must be replaced, not resumed. */
    #[Test]
    public function an_expired_stored_container_triggers_a_fresh_creation(): void
    {
        [$post, $account, $link] = $this->scenario();

        $link->update([
            'provider_container_id' => 'OLD_CONTAINER',
            'container_created_at' => now()->subHours(25),
        ]);

        Http::fake([
            '*/media_publish' => Http::response(['id' => self::PUBLISHED], 200),
            '*/'.self::IG_USER.'/media' => Http::response(['id' => self::CONTAINER], 200),
            '*/'.self::CONTAINER.'*' => Http::response(['status_code' => 'FINISHED'], 200),
        ]);

        $this->assertSame(self::PUBLISHED, $this->driver()->publish($account, $post->toArray()));

        $this->assertSame(1, $this->countCreates(), 'the 25-hour-old container was resumed instead of replaced');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'OLD_CONTAINER'));
    }

    /** A container just under the TTL is still resumed. */
    #[Test]
    public function a_container_within_its_ttl_is_still_reused(): void
    {
        [$post, $account, $link] = $this->scenario();

        $link->update([
            'provider_container_id' => self::CONTAINER,
            'container_created_at' => now()->subHours(23),
        ]);

        Http::fake([
            '*/media_publish' => Http::response(['id' => self::PUBLISHED], 200),
            '*/'.self::IG_USER.'/media' => Http::response(['id' => 'SHOULD_NOT_BE_CALLED'], 200),
            '*/'.self::CONTAINER.'*' => Http::response(['status_code' => 'FINISHED'], 200),
        ]);

        $this->driver()->publish($account, $post->toArray());

        $this->assertSame(0, $this->countCreates(), 'a 23-hour-old container was needlessly replaced');
    }

    /** A successful publish must not leave the previous attempt's error behind. */
    #[Test]
    public function publishing_clears_a_previous_attempts_error_text(): void
    {
        [$post, $account, $link] = $this->scenario();
        $link->update(['status' => 'failed', 'error' => 'Publish failed. See application logs for details.']);

        Http::fake([
            '*/media_publish' => Http::response(['id' => self::PUBLISHED], 200),
            '*/'.self::IG_USER.'/media' => Http::response(['id' => self::CONTAINER], 200),
            '*/'.self::CONTAINER.'*' => Http::response(['status_code' => 'FINISHED'], 200),
        ]);

        app(SocialPublisher::class)->publish($post->fresh());

        $fresh = $link->fresh();
        $this->assertSame('published', $fresh->status);
        $this->assertNull($fresh->error, 'a published row still claims it failed');
    }
}
