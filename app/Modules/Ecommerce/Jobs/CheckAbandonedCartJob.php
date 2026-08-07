<?php

namespace App\Modules\Ecommerce\Jobs;

use App\Events\CommerceEventReceived;
use App\Jobs\Middleware\EstablishesWorkspaceContext;
use App\Modules\Ecommerce\Models\EcommerceCart;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Support\Retry\Jitter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fires the cart.abandoned trigger if a checkout was not converted into an order
 * within the delay window. Idempotent: guarded by recovered_at / recovery_triggered_at.
 */
class CheckAbandonedCartJob implements ShouldQueue
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

    public function __construct(public readonly int $cartId) {}

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
        return [EstablishesWorkspaceContext::from(EcommerceCart::class, $this->cartId)];
    }

    public function handle(): void
    {
        $cart = EcommerceCart::find($this->cartId);
        if (! $cart || $cart->recovered_at || $cart->recovery_triggered_at || ! $cart->contact_id) {
            return;
        }

        // If an order was placed by this contact after the cart was created, it converted.
        $converted = EcommerceOrder::where('store_id', $cart->store_id)
            ->where('contact_id', $cart->contact_id)
            ->where('placed_at', '>=', $cart->abandoned_at ?? $cart->created_at)
            ->exists();

        if ($converted) {
            $cart->update(['recovered_at' => now()]);

            return;
        }

        $cart->update(['recovery_triggered_at' => now()]);

        CommerceEventReceived::dispatch(
            $cart->workspace_id,
            $cart->contact_id,
            'cart.abandoned',
            [
                'cart_total' => (string) $cart->total,
                'order_currency' => (string) $cart->currency,
                'recovery_url' => (string) $cart->recovery_url,
            ],
        );
    }
}
