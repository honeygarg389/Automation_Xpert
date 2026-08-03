<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Rules\PublicHttpUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 5;

    private static array $backoff = [60, 300, 3600, 86400, 86400]; // 1m, 5m, 1h, 1d, 1d

    public function __construct(
        private WebhookEndpoint $endpoint,
        private string $event,
        private array $payload
    ) {}

    public function handle(): void
    {
        $payloadJson = json_encode(array_merge($this->payload, [
            'event' => $this->event,
            'timestamp' => now()->toIso8601String(),
        ]));

        $signature = $this->endpoint->signature($payloadJson);

        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $this->endpoint->id,
            'event' => $this->event,
            'payload' => $this->payload,
            'attempts' => 1,
        ]);

        // Re-check the destination at send time, not just at save time. A host
        // that resolved to a public address when the endpoint was created can
        // be re-pointed at an internal one afterwards (DNS rebinding), so this
        // check — not the validation rule — is the actual control.
        $blockedReason = PublicHttpUrl::inspect((string) $this->endpoint->url);

        if ($blockedReason !== null) {
            Log::warning('Webhook delivery blocked: destination is not a public host', [
                'endpoint_id' => $this->endpoint->id,
                'reason' => $blockedReason,
            ]);

            $delivery->update([
                'response_status' => null,
                'response_body' => 'Blocked before sending: '.$blockedReason,
                'delivered_at' => null,
                'attempts' => $this->attempts(),
            ]);

            // A blocked destination will not become valid by retrying, so fail
            // without scheduling one.
            $this->fail('Webhook destination is not a public host.');

            return;
        }

        try {
            $response = Http::timeout(10)
                // Redirects are not followed: a public URL that 302s to
                // 169.254.169.254 would otherwise bypass the check above.
                ->withoutRedirecting()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Webhook-Event' => $this->event,
                    'X-Webhook-Signature' => $signature,
                    'User-Agent' => config('app.name') . ' Webhooks/1.0',
                ])
                ->post($this->endpoint->url, $payloadJson);

            $delivery->update([
                'response_status' => $response->status(),
                'response_body' => substr($response->body(), 0, 2000),
                'delivered_at' => $response->successful() ? now() : null,
                'attempts' => $this->attempts(),
            ]);

            if (! $response->successful()) {
                $this->scheduleRetry($delivery);
                $this->fail("Webhook returned {$response->status()}");
            }
        } catch (\Throwable $e) {
            Log::warning('Webhook delivery failed', ['endpoint_id' => $this->endpoint->id, 'error' => $e->getMessage()]);
            $delivery->update(['attempts' => $this->attempts()]);
            $this->scheduleRetry($delivery);
            $this->fail($e);
        }
    }

    private function scheduleRetry(WebhookDelivery $delivery): void
    {
        $attempt = $this->attempts() - 1;
        $seconds = self::$backoff[$attempt] ?? self::$backoff[array_key_last(self::$backoff)];
        $delivery->update(['next_retry_at' => now()->addSeconds($seconds)]);
        $this->release($seconds);
    }

    public function backoff(): array
    {
        return self::$backoff;
    }
}
