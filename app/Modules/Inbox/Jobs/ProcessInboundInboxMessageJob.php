<?php

namespace App\Modules\Inbox\Jobs;

use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Inbox\Services\InstagramDriver;
use App\Modules\Inbox\Services\MessengerDriver;
use App\Support\Retry\Jitter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInboundInboxMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry up to 5 times on transient failures. */
    public int $tries = 5;

    /** Hard timeout per attempt. */
    public int $timeout = 120;

    /** Max unhandled exceptions before marking failed without retrying. */
    public int $maxExceptions = 3;

    /** Exponential back-off in seconds. */
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
        private readonly string $object,
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
            'reason: ONE Meta payload can carry events for several pages and therefore several workspaces; webhooks/meta/{token} validates a platform-global token, so the job has no tenant to resolve. Context is established PER MESSAGE inside the Messenger/Instagram drivers',
        )];
    }

    public function handle(InstagramDriver $instagram, MessengerDriver $messenger): void
    {
        match ($this->object) {
            'instagram' => $instagram->processWebhookPayload($this->payload),
            'page' => $messenger->processWebhookPayload($this->payload),
            default => null,
        };
    }
}
