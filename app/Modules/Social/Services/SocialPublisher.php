<?php

namespace App\Modules\Social\Services;

use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Social\Exceptions\PublishNotReadyException;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Models\SocialPostAccount;
use App\Modules\Social\Services\Drivers\FacebookDriver;
use App\Modules\Social\Services\Drivers\InstagramSocialDriver;
use App\Modules\Social\Services\Drivers\LinkedInDriver;
use App\Modules\Social\Services\Drivers\SocialNetworkInterface;
use App\Modules\Social\Services\Drivers\TikTokDriver;
use App\Modules\Social\Services\Drivers\TwitterDriver;
use App\Modules\Social\Services\Drivers\YoutubeDriver;
use Illuminate\Support\Facades\Log;

class SocialPublisher
{
    /** @var array<string, SocialNetworkInterface> */
    private array $drivers;

    public function __construct()
    {
        $this->drivers = [
            'facebook' => new FacebookDriver,
            'instagram' => new InstagramSocialDriver,
            'linkedin' => new LinkedInDriver,
            'twitter' => new TwitterDriver,
            'youtube' => new YoutubeDriver,
            'tiktok' => new TikTokDriver,
        ];
    }

    public function publish(SocialPost $post): void
    {
        $post->update(['status' => 'publishing']);

        // Scope accounts to the post's own workspace to prevent cross-workspace publishing.
        $accounts = SocialAccount::where('workspace_id', $post->workspace_id)
            ->whereIn('id', $post->target_accounts ?? [])
            ->get();

        $results = [];

        /** @var list<int> Accounts the platform is still processing. */
        $deferred = [];

        foreach ($accounts as $account) {
            $link = SocialPostAccount::firstOrCreate(
                ['post_id' => $post->id, 'social_account_id' => $account->id],
                ['status' => 'pending']
            );

            // On job retry, skip accounts already successfully published.
            if ($link->status === 'published') {
                $results[$account->id] = ['status' => 'published', 'post_id' => $link->platform_post_id];

                continue;
            }

            $driver = $this->drivers[$account->network] ?? null;
            if (! $driver) {
                $link->update(['status' => 'failed', 'error' => "No driver for network {$account->network}."]);
                $results[$account->id] = ['status' => 'failed'];

                continue;
            }

            try {
                $platformId = $driver->publish($account, $post->toArray());
                // ⚠️ `error` is cleared: a row that succeeded on retry must not keep
                // the previous attempt's failure text, or a published post reads as failed.
                $link->update(['status' => 'published', 'platform_post_id' => $platformId, 'published_at' => now(), 'error' => null]);
                $results[$account->id] = ['status' => 'published', 'post_id' => $platformId];
            } catch (PublishNotReadyException $e) {
                // ⚠️ NOT A FAILURE — the platform accepted the upload and is still
                // processing it. Marking this account failed would discard work
                // already in flight, and the driver has persisted whatever it
                // needs (Instagram: the container id) to resume rather than
                // restart. The account stays `pending` and the job is asked to
                // retry, which is what PublishSocialPostJob's backoff exists for.
                //
                // Collected rather than thrown here, so a slow account cannot
                // stop the remaining accounts from publishing.
                Log::info('Social publish deferred; platform still processing', [
                    'post_id' => $post->id,
                    'account_id' => $account->id,
                    'reason' => $e->getMessage(),
                ]);
                $link->update(['status' => 'pending', 'error' => null]);
                $results[$account->id] = ['status' => 'pending'];
                $deferred[] = $account->id;

                continue;
            } catch (\Throwable $e) {
                // Store a sanitized message; full details go to the log.
                Log::error('Social publish failed', [
                    'post_id' => $post->id,
                    'account_id' => $account->id,
                    'network' => $account->network,
                    'error' => $e->getMessage(),
                ]);
                $link->update(['status' => 'failed', 'error' => 'Publish failed. See application logs for details.']);
                $results[$account->id] = ['status' => 'failed'];
            }
        }

        /**
         * ⚠️ RE-THROWN BEFORE THE POST IS GIVEN A FINAL STATUS.
         *
         * A deferred account has not failed and has not published, so neither
         * terminal status is true yet. Writing one here — and then throwing —
         * would leave the post claiming an outcome the retry is about to change,
         * and marking it `failed` would additionally stop the UI showing it as
         * in progress. The post stays `publishing`, which is what it is.
         *
         * Accounts that DID publish above are already committed to their own
         * rows, and the `status === 'published'` guard at the top of the loop
         * means the retry will skip them rather than post twice.
         */
        if ($deferred !== []) {
            $post->update(['publish_results' => $results]);

            throw new PublishNotReadyException(
                'Still processing on '.count($deferred).' account(s); retrying.'
            );
        }

        $succeededCount = collect($results)->filter(fn ($r) => $r['status'] === 'published')->count();
        $allFailed = $succeededCount === 0;

        $finalStatus = match (true) {
            $allFailed => 'failed',
            $succeededCount < count($results) => 'published', // partial success still marks published
            default => 'published',
        };

        $post->update([
            'status' => $finalStatus,
            'published_at' => $allFailed ? null : now(),
            'publish_results' => $results,
        ]);

        if (! $allFailed) {
            UsageMeter::track($post->workspace_id, 'social_posts');
        }
    }
}
