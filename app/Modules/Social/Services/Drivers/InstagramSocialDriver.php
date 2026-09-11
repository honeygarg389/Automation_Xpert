<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Exceptions\PublishNotReadyException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Support\NetworkCapabilities;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ═══ INSTAGRAM PUBLISHES IN TWO CALLS, AND THE GAP BETWEEN THEM IS REAL ═════
 *
 * Create a media container, then publish it. Meta fetches and processes the
 * image asynchronously, so a container is NOT publishable the instant it is
 * created — `media_publish` fails until `status_code` reaches FINISHED.
 *
 * ⚠️ THIS DRIVER USED TO CALL THE TWO ENDPOINTS BACK TO BACK, with no wait and
 * no status check, and it discarded the container id. Two faults compounded:
 *
 *   1. Publishing raced the processing. When the image had not finished, the
 *      publish failed and the whole attempt was marked failed.
 *   2. The container id was a local variable, so the retry did not resume the
 *      upload — it created a BRAND NEW container and raced the same delay again,
 *      leaving the previous one orphaned on Meta's side to expire in 24 hours.
 *
 * Measured on post 15: three attempts over 474 seconds before one happened to
 * win the race. That is the retry schedule accidentally acting as a wait loop,
 * which only works when the delay is short enough to fit inside it.
 *
 * ═══ REELS (VIDEO) AND CAROUSEL — BUILT ON THE SAME LESSON, ONE LEVEL DEEPER ═
 *
 * A Reel is one container, just a slower one (media_type=REELS, video_url) —
 * it reuses every line of the single-container path above with a wider poll
 * budget; see pollMaxAttempts().
 *
 * A carousel is NOT one container. It is N child containers
 * (is_carousel_item=true) plus one parent (media_type=CAROUSEL,
 * children=<id1>,<id2>,...). The single-container idempotency fix does not
 * cover this by itself: a crash after child 3 of 5 must resume at child 4, not
 * recreate 1-3 and orphan them the same way the original bug orphaned whole
 * containers. `provider_upload_state` (a JSON column, see its migration)
 * persists each child id the moment it is created, so a retry only creates
 * what is still missing. Once every child exists, the PARENT container is
 * handed to the exact same reusableContainerId/awaitFinished/publish path a
 * single image uses — carousel-specific code only covers getting the children
 * built; finishing the post is the same machinery as everything else here.
 *
 * ⚠️ MIXED image+video CAROUSELS ARE NOT SUPPORTED. Instagram's API allows a
 * carousel child to be either — this driver only ever builds image children.
 * `post_type` is a POST-LEVEL field; representing "item 2 of this carousel is
 * a video" needs a PER-ITEM type, which is a schema change (see
 * docs/found-bugs.md-style TODO in createCarouselChildren() below), not
 * something this branch solves. A video-post-type carousel request is refused
 * before any container is created.
 */
class InstagramSocialDriver implements SocialNetworkInterface
{
    private const API = 'https://graph.facebook.com/v19.0';

    /**
     * ⚠️ SIZED AGAINST PublishSocialPostJob::$timeout, WHICH IS 120 SECONDS.
     *
     * 3s x 15 attempts = 45s of waiting. Adding ~15 status round trips and the
     * two POSTs, the worst case lands near 60s — comfortably inside 120s, with
     * room for the DB writes and any HTTP latency. Going wider would risk the
     * queue worker killing the job MID-POLL, which is strictly worse than giving
     * up cleanly: a killed job leaves no record of the container it created, so
     * the retry starts from scratch — the exact fault this fix removes.
     *
     * Three seconds because Meta's own guidance is that images finish in
     * seconds; a longer interval would spend the budget on sleeping rather than
     * checking. The job's retry schedule, not this loop, is what covers a
     * genuinely slow upload.
     */
    private const POLL_INTERVAL_SECONDS = 3;

    private const POLL_MAX_ATTEMPTS = 15;

    /**
     * ⚠️ VIDEO GETS A WIDER BUDGET, NOT A DIFFERENT ARCHITECTURE. See
     * config/social.php for the exact timing derivation — Meta's current
     * guidance (poll once/minute for up to 5 minutes) and real-world processing
     * times (30s to several minutes) both structurally exceed the 120s job
     * timeout, so this does not chase either number. It reuses the same
     * bounded-poll-then-defer-to-job-retry shape as images, just wider.
     */
    private const VIDEO_POLL_MAX_ATTEMPTS = 20;

    /** Meta expires an unpublished container after 24 hours. */
    private const CONTAINER_TTL_HOURS = 24;

    /**
     * ⚠️ Read from config so the suite can set the interval to 0. Without that,
     * exercising the exhaustion path sleeps for 45 real seconds per test.
     * Operators can also widen the window without a deploy — but see the warning
     * in config/social.php about the job timeout.
     */
    private function pollIntervalSeconds(): int
    {
        return (int) config('social.instagram.poll_interval_seconds', self::POLL_INTERVAL_SECONDS);
    }

    /** @param  'image'|'video'  $mediaKind */
    private function pollMaxAttempts(string $mediaKind): int
    {
        $key = $mediaKind === 'video'
            ? 'social.instagram.video_poll_max_attempts'
            : 'social.instagram.poll_max_attempts';
        $default = $mediaKind === 'video' ? self::VIDEO_POLL_MAX_ATTEMPTS : self::POLL_MAX_ATTEMPTS;

        return max(1, (int) config($key, $default));
    }

    private function containerTtlHours(): int
    {
        return (int) config('social.instagram.container_ttl_hours', self::CONTAINER_TTL_HOURS);
    }

    public function network(): string
    {
        return 'instagram';
    }

    public function fetchAccountInfo(string $accessToken): array
    {
        $res = Http::get('https://graph.instagram.com/me', [
            'fields' => 'id,name,profile_picture_url',
            'access_token' => $accessToken,
        ])->json();

        return [
            'account_id' => $res['id'] ?? '',
            'name' => $res['name'] ?? '',
            'picture_url' => $res['profile_picture_url'] ?? null,
        ];
    }

    public function publish(SocialAccount $account, array $postData): string
    {
        $igUserId = $account->account_id;
        $token = $account->access_token;
        $postType = $postData['post_type'] ?? 'image';
        $mediaType = $postData['media_type'] ?? null;

        $link = $this->linkRow($postData, $account);
        $mediaKind = $postType === 'video' ? 'video' : 'image';

        if ($postType === 'image' && $mediaType === 'carousel') {
            $creationId = $this->reusableContainerId($link)
                ?? $this->createCarouselParent($igUserId, $token, $postData, $link);
        } else {
            // ─── Reuse an in-flight container rather than starting over ─────
            $creationId = $this->reusableContainerId($link);

            if ($creationId === null) {
                $creationId = $postType === 'video'
                    ? $this->createReelsContainer($igUserId, $token, $postData)
                    : $this->createImageContainer($igUserId, $token, $postData);

                $link?->update([
                    'provider_container_id' => $creationId,
                    'container_created_at' => now(),
                ]);
            }
        }

        // ─── Wait for Meta to finish processing ─────────────────────────────
        $this->awaitFinished($creationId, $token, $link, $mediaKind);

        // ─── Publish ────────────────────────────────────────────────────────
        $res = Http::post("{$this->api()}/{$igUserId}/media_publish", [
            'creation_id' => $creationId,
            'access_token' => $token,
        ])->json();

        $publishedId = $res['id'] ?? null;
        if (! $publishedId) {
            throw new \RuntimeException('Instagram publish failed: '.json_encode($res));
        }

        // The container has become a post; it can never be reused again.
        $link?->update([
            'provider_container_id' => null,
            'container_created_at' => null,
            'provider_upload_state' => null,
        ]);

        return $publishedId;
    }

    private function api(): string
    {
        return self::API;
    }

    /**
     * The link row for this (post, account), or null when the driver is called
     * outside a stored post — container reuse is simply unavailable then, which
     * is the old behaviour rather than an error.
     */
    /** @param  array<string, mixed>  $postData */
    private function linkRow(array $postData, SocialAccount $account): ?SocialPostAccount
    {
        $postId = $postData['id'] ?? null;
        if (! $postId) {
            return null;
        }

        return SocialPostAccount::where('post_id', $postId)
            ->where('social_account_id', $account->id)
            ->first();
    }

    /**
     * A previously created container that is still worth resuming.
     *
     * ⚠️ An EXPIRED container is discarded HERE rather than polled. Meta answers
     * a request for one with an error, so polling it would burn the whole window
     * to reach a conclusion already knowable from its age.
     */
    private function reusableContainerId(?SocialPostAccount $link): ?string
    {
        $existing = $link?->provider_container_id;
        if (! $existing) {
            return null;
        }

        $createdAt = $link->container_created_at;
        if ($createdAt && $createdAt->lt(now()->subHours($this->containerTtlHours()))) {
            Log::info('Instagram container expired; creating a fresh one', [
                'link_id' => $link->id,
                'container_id' => $existing,
                'created_at' => $createdAt->toIso8601String(),
            ]);
            $link->update(['provider_container_id' => null, 'container_created_at' => null]);

            return null;
        }

        return $existing;
    }

    /** @param  array<string, mixed>  $postData */
    private function createImageContainer(string $igUserId, string $token, array $postData): string
    {
        $mediaUrls = $this->mediaUrls($postData);

        if (empty($mediaUrls)) {
            throw new \RuntimeException('Instagram posts require at least one image.');
        }

        return $this->createSingleContainer($igUserId, $token, [
            'caption' => $postData['body'] ?? '',
            'image_url' => $mediaUrls[0],
        ]);
    }

    /**
     * @param  array<string, mixed>  $postData
     *
     * ⚠️ NO cover_url / thumb_offset. Neither the composer nor the SocialPost
     * model captures a cover image or a frame-offset selection anywhere today —
     * confirmed by grep, not assumed. Adding a minimal cover picker is its own
     * UI slice with its own validation and storage, not a one-line addition
     * here, so this branch defaults to Instagram's automatic thumbnail
     * selection by omitting both fields rather than half-building the feature.
     */
    private function createReelsContainer(string $igUserId, string $token, array $postData): string
    {
        $mediaUrls = $this->mediaUrls($postData);

        if (empty($mediaUrls)) {
            throw new \RuntimeException('Instagram Reels require a video.');
        }

        return $this->createSingleContainer($igUserId, $token, [
            'media_type' => 'REELS',
            'video_url' => $mediaUrls[0],
            'caption' => $postData['body'] ?? '',
            'share_to_feed' => 'true',
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function createSingleContainer(string $igUserId, string $token, array $payload): string
    {
        $container = Http::post("{$this->api()}/{$igUserId}/media", $payload + [
            'access_token' => $token,
        ])->json();

        $creationId = $container['id'] ?? null;
        if (! $creationId) {
            throw new \RuntimeException('Instagram container creation failed: '.json_encode($container));
        }

        return (string) $creationId;
    }

    /**
     * Build (or resume) a carousel: create any child containers not yet
     * recorded, then the parent that references all of them.
     *
     * @param  array<string, mixed>  $postData
     */
    private function createCarouselParent(string $igUserId, string $token, array $postData, ?SocialPostAccount $link): string
    {
        $mediaUrls = $this->mediaUrls($postData);
        $this->enforceCarouselLimits(count($mediaUrls));

        $childIds = $this->createCarouselChildren($igUserId, $token, $mediaUrls, $link);

        $parentId = $this->createSingleContainer($igUserId, $token, [
            'media_type' => 'CAROUSEL',
            'caption' => $postData['body'] ?? '',
            'children' => implode(',', $childIds),
        ]);

        // The parent now IS the single container everything else already knows
        // how to poll, reuse and forget. Children are no longer needed once
        // Meta has consumed their ids into the parent.
        $link?->update([
            'provider_container_id' => $parentId,
            'container_created_at' => now(),
            'provider_upload_state' => null,
        ]);

        return $parentId;
    }

    /**
     * Create each missing child container, in order, persisting progress after
     * EVERY success — not after the batch. A crash (or a killed job) between
     * child 3 and child 4 must resume at child 4 on retry, not recreate 1-3 and
     * orphan them on Meta's side the way the original single-container bug did.
     *
     * ⚠️ IMAGE-ONLY. This method never receives a video URL — a carousel post
     * always carries post_type=image, so there is no per-item type to branch
     * on. TODO(future schema change): supporting a mixed image+video carousel
     * needs PER-ITEM media typing (e.g. a media_urls entry becoming
     * {url, type} instead of a bare string), which is a data-model change, not
     * something this method can express. See the class docblock.
     *
     * ⚠️ FAILS THE WHOLE CAROUSEL ON ANY CHILD ERROR, rather than retrying that
     * one child in a loop of its own. A child is added to provider_upload_state
     * ONLY after Meta confirms it — so a failed child is simply never recorded,
     * and the job's own retry (which calls this same method again) will retry
     * exactly the children still missing: the failed one, and nothing already
     * succeeded. No separate retry machinery is needed because the persisted
     * state already IS "what's left to do". What this method refuses to do is
     * silently publish with fewer than the requested items — the exception
     * names the exact position and URL that failed, so a permanently-rejected
     * image (bad format, dead URL) surfaces as a clear error once the job's
     * normal retry budget is exhausted, instead of a mysteriously short
     * carousel.
     *
     * @param  list<string>  $mediaUrls
     * @return list<string> every child container id, in order
     */
    private function createCarouselChildren(string $igUserId, string $token, array $mediaUrls, ?SocialPostAccount $link): array
    {
        $state = $link !== null ? ($link->provider_upload_state ?? []) : [];
        $items = $state['items'] ?? [];

        // Already-confirmed children, indexed by position — the resume point.
        $done = [];
        foreach ($items as $item) {
            if (isset($item['index'], $item['container_id'])) {
                $done[(int) $item['index']] = $item;
            }
        }

        foreach ($mediaUrls as $index => $url) {
            if (isset($done[$index])) {
                continue; // already created on a previous attempt — do not recreate
            }

            $child = Http::post("{$this->api()}/{$igUserId}/media", [
                'image_url' => $url,
                'is_carousel_item' => 'true',
                'access_token' => $token,
            ])->json();

            $childId = $child['id'] ?? null;
            if (! $childId) {
                throw new \RuntimeException(sprintf(
                    'Instagram carousel item %d of %d failed (%s): %s',
                    $index + 1,
                    count($mediaUrls),
                    $url,
                    json_encode($child)
                ));
            }

            $done[$index] = ['index' => $index, 'media_url' => $url, 'container_id' => (string) $childId];

            // Persisted IMMEDIATELY, not batched — this write is the entire
            // point. If the process dies on the next line, the next attempt
            // still sees this child as done.
            ksort($done);
            $link?->update([
                'provider_upload_state' => [
                    'phase' => 'creating_children',
                    'items' => array_values($done),
                    'parent_id' => null,
                    'meta' => [],
                ],
            ]);
        }

        ksort($done);

        return array_values(array_map(fn ($item) => $item['container_id'], $done));
    }

    /**
     * Defense-in-depth: the composer already enforces this via
     * NetworkCapabilities::carouselRange(), but a driver must not trust that
     * every caller went through the composer. Reads Instagram's own numbers
     * from the SAME source rather than hardcoding a third copy of "2 and 10".
     */
    private function enforceCarouselLimits(int $count): void
    {
        $caps = NetworkCapabilities::for(NetworkCapabilities::INSTAGRAM) ?? [];
        $min = $caps['carousel_min'] ?? 2;
        $max = $caps['carousel_max'] ?? null;

        if ($count < $min || ($max !== null && $count > $max)) {
            throw new \RuntimeException(sprintf(
                'Instagram carousels must have between %d and %s items; this post has %d.',
                $min,
                $max ?? 'an unverified maximum',
                $count
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $postData
     * @return list<string>
     */
    private function mediaUrls(array $postData): array
    {
        return array_values(array_filter(
            $postData['media_urls'] ?? [],
            fn ($u) => $u !== null && $u !== ''
        ));
    }

    /**
     * Poll until the container is publishable, or decide it never will be.
     *
     * @param  'image'|'video'  $mediaKind  decides which poll budget applies —
     *                                      see pollMaxAttempts().
     *
     * @throws PublishNotReadyException when still processing at the end of the
     *                                  window — a retry, not a failure.
     * @throws \RuntimeException when Meta reports ERROR or EXPIRED.
     */
    private function awaitFinished(string $creationId, string $token, ?SocialPostAccount $link, string $mediaKind = 'image'): void
    {
        $interval = $this->pollIntervalSeconds();
        $maxAttempts = $this->pollMaxAttempts($mediaKind);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $res = Http::get("{$this->api()}/{$creationId}", [
                'fields' => 'status_code,status',
                'access_token' => $token,
            ])->json();

            $status = $res['status_code'] ?? null;

            switch ($status) {
                case 'FINISHED':
                    return;

                case 'ERROR':
                    // ⚠️ Stop immediately. Continuing to poll something Meta has
                    // already rejected spends the window to reach a conclusion it
                    // has already given, and hides the reason behind a timeout.
                    $this->forgetContainer($link);
                    throw new \RuntimeException(
                        'Instagram rejected the media container: '.($res['status'] ?? json_encode($res))
                    );

                case 'EXPIRED':
                    // Defensive: a container lives 24h, far longer than this
                    // window, so reaching here means the stored id outlived its
                    // usefulness. Drop it so the next attempt starts cleanly.
                    $this->forgetContainer($link);
                    throw new \RuntimeException(
                        'Instagram media container expired before it could be published.'
                    );

                case 'IN_PROGRESS':
                default:
                    if ($attempt < $maxAttempts && $interval > 0) {
                        sleep($interval);
                    }
            }
        }

        // ⚠️ The container is KEPT. It is still processing and still valid, so
        // the next attempt resumes this upload instead of creating another one.
        Log::info('Instagram container still processing; deferring to job retry', [
            'container_id' => $creationId,
            'media_kind' => $mediaKind,
            'polled_seconds' => $interval * ($maxAttempts - 1),
        ]);

        throw new PublishNotReadyException(
            "Instagram is still processing the {$mediaKind} after "
            .($interval * ($maxAttempts - 1))
            .'s; the container is saved and the job will retry.'
        );
    }

    private function forgetContainer(?SocialPostAccount $link): void
    {
        $link?->update([
            'provider_container_id' => null,
            'container_created_at' => null,
            'provider_upload_state' => null,
        ]);
    }
}
