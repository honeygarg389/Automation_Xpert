<?php

namespace App\Modules\Ecommerce\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Clients\StoreClientFactory;
use App\Support\Retry\Jitter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RegisterStoreWebhooksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Base retry schedule in seconds, before jitter. Previously none: both attempts fired back-to-back. */
    private const BACKOFF_SECONDS = [30, 120];

    /** Ceiling on any single jittered delay: 120 + 30% jitter. */
    public const BACKOFF_CAP_SECONDS = 156;

    /** @return list<int> */
    public function backoff(): array
    {
        return Jitter::jittered(self::BACKOFF_SECONDS);
    }

    public function __construct(public readonly int $storeId) {}

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
        return [EstablishesWorkspaceContext::from(EcommerceStore::class, $this->storeId)];
    }

    public function handle(): void
    {
        $store = EcommerceStore::find($this->storeId);
        if (! $store) {
            return;
        }

        $callbackUrl = EcommerceStore::webhookUrlFor($store);
        $result = StoreClientFactory::for($store)->registerWebhooks($callbackUrl);

        if ($result['ok']) {
            $store->update(['external_meta' => array_merge($store->external_meta ?? [], ['webhooks_registered' => true])]);
        } else {
            Log::warning('ecommerce.webhook.register_failed', [
                'store' => $store->id,
                'message' => $result['message'],
            ]);
        }
    }
}
