<?php

namespace App\Modules\Ecommerce\Jobs;

use App\Modules\Ecommerce\Models\EcommerceProduct;
use App\Modules\Ecommerce\Models\EcommerceStore;
use App\Modules\Ecommerce\Services\Clients\StoreClientFactory;
use App\Modules\Ecommerce\Services\PayloadNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Imports/refreshes store products + inventory levels, one page per invocation,
 * chaining the next page via re-dispatch. Sets products_synced_at on completion.
 */
class SyncStoreProductsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $storeId,
        public readonly ?string $cursor = null,
    ) {}

    public function handle(PayloadNormalizer $normalizer): void
    {
        $store = EcommerceStore::find($this->storeId);
        if (! $store) {
            return;
        }

        $page = StoreClientFactory::for($store)->fetchProducts($this->cursor);

        foreach ($page['products'] as $raw) {
            $product = $normalizer->mapProduct($store->platform, $raw);
            if (($product['external_id'] ?? '') === '') {
                continue;
            }

            EcommerceProduct::updateOrCreate(
                ['store_id' => $store->id, 'external_id' => $product['external_id']],
                array_merge($product, ['workspace_id' => $store->workspace_id]),
            );
        }

        if ($page['next'] !== null && $page['next'] !== $this->cursor) {
            self::dispatch($store->id, $page['next']);

            return;
        }

        $store->update(['products_synced_at' => now()]);
    }
}
