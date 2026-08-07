<?php

namespace App\Modules\Social\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Social\Models\SocialPost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchScheduledPostsJob implements ShouldQueue
{
    use Queueable;

    /**
     * CROSS-TENANT BY DESIGN — a decision, not an omission.
     *
     * This job's entire function is to scan every workspace for due work.
     * Giving it a tenant context would silently reduce it to one tenant's.
     * Declared explicitly so it is counted rather than looking like a job
     * somebody forgot to scope.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::crossTenant(
            'reason: the scheduler scans EVERY workspace for posts due to publish; scoping it would silently stop publishing for all but one tenant',
        )];
    }

    public function handle(): void
    {
        // Atomically flip status to 'publishing' before dispatching so a second
        // scheduler tick cannot pick up the same post and dispatch it twice.
        $affected = SocialPost::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get(['id']);

        foreach ($affected as $post) {
            // updateOrFail pattern: only dispatch if we are the one who flipped the status.
            $updated = SocialPost::where('id', $post->id)
                ->where('status', 'scheduled')
                ->update(['status' => 'publishing']);

            if ($updated) {
                PublishSocialPostJob::dispatch($post->id)->onQueue('social');
            }
        }
    }
}
