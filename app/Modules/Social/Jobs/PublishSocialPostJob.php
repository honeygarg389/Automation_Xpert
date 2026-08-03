<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\SocialPublisher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishSocialPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $postId) {}

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
