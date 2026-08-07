<?php

namespace App\Modules\Whatsapp\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Support\Retry\Jitter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TemplateSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Base retry schedule in seconds, before jitter. Previously none: all 3 attempts fired back-to-back. */
    private const BACKOFF_SECONDS = [30, 120, 300];

    /** Ceiling on any single jittered delay: 300 + 30% jitter. */
    public const BACKOFF_CAP_SECONDS = 390;

    /** @return list<int> */
    public function backoff(): array
    {
        return Jitter::jittered(self::BACKOFF_SECONDS);
    }

    public function __construct(public readonly int $wabaDbId) {}

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
        return [EstablishesWorkspaceContext::from(WhatsappBusinessAccount::class, $this->wabaDbId)];
    }

    public function handle(): void
    {
        $waba = WhatsappBusinessAccount::find($this->wabaDbId);
        if (! $waba) {
            return;
        }

        $client = CloudApiClient::forWorkspace($waba->workspace_id);
        if (! $client) {
            Log::warning('TemplateSyncJob: no CloudApiClient for workspace '.$waba->workspace_id);

            return;
        }

        $templates = $client->fetchTemplates($waba->waba_id);

        foreach ($templates as $tpl) {
            WhatsappTemplate::updateOrCreate(
                ['workspace_id' => $waba->workspace_id, 'waba_id' => $waba->waba_id, 'name' => $tpl['name'], 'language' => $tpl['language']],
                [
                    'category' => $tpl['category'] ?? 'MARKETING',
                    'status' => $tpl['status'] ?? 'PENDING',
                    'components' => $tpl['components'] ?? [],
                    'rejection_reason' => $tpl['rejection_reason'] ?? null,
                    'meta_template_id' => $tpl['id'] ?? null,
                ]
            );
        }
    }
}
