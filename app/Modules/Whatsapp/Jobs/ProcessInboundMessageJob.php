<?php

namespace App\Modules\Whatsapp\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use App\Support\Retry\Jitter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessInboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry up to 5 times on transient failures (network, DB lock, etc.). */
    public int $tries = 5;

    /** Hard timeout per attempt — covers upstream API calls and DB writes. */
    public int $timeout = 120;

    /** Max unhandled exceptions before the job is marked failed without retrying. */
    public int $maxExceptions = 3;

    /** Exponential back-off in seconds: 30 s, 60 s, 120 s, 240 s, 300 s. */
    private const BACKOFF_SECONDS = [30, 60, 120, 240, 300];

    /**
     * Hard ceiling. The schedule already grew and capped correctly, so jitter is
     * capped at the same 300 s rather than being allowed to lift the tail.
     */
    public const BACKOFF_CAP_SECONDS = 300;

    /** @return list<int> */
    public function backoff(): array
    {
        return Jitter::jittered(self::BACKOFF_SECONDS, Jitter::DEFAULT_RATIO, self::BACKOFF_CAP_SECONDS);
    }

    public function __construct(
        private readonly array $payload,
        private readonly string $verifyToken,
    ) {}

    /**
     * CROSS-TENANT BY DESIGN — and for a different reason from the schedulers.
     *
     * A scheduler is cross-tenant because it SHOULD see every workspace.
     * This job is cross-tenant because it CANNOT KNOW its workspace: one
     * webhook payload legitimately carries messages for several tenants, so
     * there is no single correct answer at job level.
     *
     * Context is established per message inside the driver, at the point the
     * routing identifier resolves to a ChannelAccount. Declaring it here
     * rather than leaving it unset matters: the scope fails closed, so 'no
     * context' would give the driver nothing at all rather than everything.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [EstablishesWorkspaceContext::crossTenant(
            'reason: ONE payload can carry messages for several workspaces — the global callback URL is shared by every WABA and the phone_number_id that identifies the tenant lives per change inside entry[]. Context is established PER MESSAGE inside WhatsappDriver, where the workspace is first knowable',
        )];
    }

    public function handle(WhatsappDriver $driver): void
    {
        $driver->processWebhookPayload($this->payload, $this->verifyToken);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessInboundMessageJob failed permanently', [
            'error' => $e->getMessage(),
            'verify_token' => substr($this->verifyToken, 0, 8).'…',
            'entry_ids' => collect($this->payload['entry'] ?? [])->pluck('id')->all(),
        ]);
    }
}
