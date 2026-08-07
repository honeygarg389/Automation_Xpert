<?php

namespace App\Jobs;

use App\Exceptions\MissingWorkspaceContextException;
use App\Models\User;
use App\Notifications\WorkspaceExportReadyNotification;
use App\Services\WorkspaceExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateWorkspaceExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    /**
     * The workspace is carried on the job, captured at dispatch. It cannot be
     * recovered here: a queued job has no authenticated user, so
     * WorkspaceContext::id() is null, and a User carries a HOME workspace but
     * not a current one. Deriving it here exported the wrong workspace whenever
     * the requester had switched — see BUG-008.
     *
     * Nullable only so that jobs already queued when this shipped fail loudly
     * rather than silently exporting home data.
     */
    public function __construct(private int $userId, private ?int $workspaceId = null) {}

    public function handle(WorkspaceExportService $exportService): void
    {
        $user = User::findOrFail($this->userId);

        // Fail loudly. A silently-wrong GDPR export is worse than a failed one:
        // a failure is retryable and visible, wrong data gets handed to a
        // regulator. See docs/phase-0-tenant-isolation-plan.md B.3.
        if ($this->workspaceId === null) {
            throw MissingWorkspaceContextException::forJob(self::class, $this->userId);
        }

        $storagePath = $exportService->generate($user, $this->workspaceId);

        // Create a 72-hour signed URL so only the requester can download it
        $signedUrl = Storage::temporaryUrl($storagePath, now()->addHours(72));

        $user->notify(new WorkspaceExportReadyNotification($signedUrl));
    }
}
