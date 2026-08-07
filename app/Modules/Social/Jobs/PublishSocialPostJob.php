<?php

namespace App\Modules\Social\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\SocialPublisher;
use App\Support\Retry\Jitter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishSocialPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** Base retry schedule in seconds, before jitter. Previously none: all 3 attempts fired back-to-back. */
    private const BACKOFF_SECONDS = [30, 120, 300];

    /** Ceiling on any single jittered delay: 300 + 30% jitter. */
    public const BACKOFF_CAP_SECONDS = 390;

    /** @return list<int> */
    public function backoff(): array
    {
        return Jitter::jittered(self::BACKOFF_SECONDS);
    }

    public function __construct(public readonly int $postId) {}

    /**
     * Phase 0: establish this job's tenant BEFORE handle() runs.
     *
     * handle()'s first statement loads a scoped model. Without context that
     * lookup returns null and the early return below turns a tenant-blind job
     * into a silent success. The middleware resolves the workspace with one
     * deliberately unscoped column read, and throws if it cannot.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::from(SocialPost::class, $this->postId)];
    }

    public function handle(SocialPublisher $publisher): void
    {
        $post = SocialPost::find($this->postId);

        // Post deleted or already fully published — nothing to do.
        if (! $post || $post->status === 'published') {
            return;
        }

        $publisher->publish($post);
    }
}
