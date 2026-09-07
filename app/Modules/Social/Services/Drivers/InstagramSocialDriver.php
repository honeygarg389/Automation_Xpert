<?php

namespace App\Modules\Social\Services\Drivers;

use App\Modules\Social\Exceptions\PublishNotReadyException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPostAccount;
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

    private function pollMaxAttempts(): int
    {
        return max(1, (int) config('social.instagram.poll_max_attempts', self::POLL_MAX_ATTEMPTS));
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

        $link = $this->linkRow($postData, $account);

        // ─── Reuse an in-flight container rather than starting over ─────────
        $creationId = $this->reusableContainerId($link);

        if ($creationId === null) {
            $creationId = $this->createContainer($igUserId, $token, $postData);
            $link?->update([
                'provider_container_id' => $creationId,
                'container_created_at' => now(),
            ]);
        }

        // ─── Wait for Meta to finish processing ─────────────────────────────
        $this->awaitFinished($creationId, $token, $link);

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
        $link?->update(['provider_container_id' => null, 'container_created_at' => null]);

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
    private function createContainer(string $igUserId, string $token, array $postData): string
    {
        $mediaUrls = array_values(array_filter(
            $postData['media_urls'] ?? [],
            fn ($u) => $u !== null && $u !== ''
        ));

        if (empty($mediaUrls)) {
            throw new \RuntimeException('Instagram posts require at least one image.');
        }

        $container = Http::post("{$this->api()}/{$igUserId}/media", [
            'caption' => $postData['body'] ?? '',
            'image_url' => $mediaUrls[0],
            'access_token' => $token,
        ])->json();

        $creationId = $container['id'] ?? null;
        if (! $creationId) {
            throw new \RuntimeException('Instagram container creation failed: '.json_encode($container));
        }

        return (string) $creationId;
    }

    /**
     * Poll until the container is publishable, or decide it never will be.
     *
     * @throws PublishNotReadyException when still processing at the end of the
     *                                  window — a retry, not a failure.
     * @throws \RuntimeException when Meta reports ERROR or EXPIRED.
     */
    private function awaitFinished(string $creationId, string $token, ?SocialPostAccount $link): void
    {
        $interval = $this->pollIntervalSeconds();
        $maxAttempts = $this->pollMaxAttempts();

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
            'polled_seconds' => $interval * ($maxAttempts - 1),
        ]);

        throw new PublishNotReadyException(
            'Instagram is still processing the media after '
            .($interval * ($maxAttempts - 1))
            .'s; the container is saved and the job will retry.'
        );
    }

    private function forgetContainer(?SocialPostAccount $link): void
    {
        $link?->update(['provider_container_id' => null, 'container_created_at' => null]);
    }
}
