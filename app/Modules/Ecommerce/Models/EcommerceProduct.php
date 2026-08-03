<?php

namespace App\Modules\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $store_id
 * @property string $external_id
 * @property string $platform
 * @property string $name
 * @property string|null $sku
 * @property float $price
 * @property int|null $inventory_quantity
 * @property string|null $status
 * @property string|null $image_url
 */
class EcommerceProduct extends Model
{
    protected $table = 'ecommerce_products';

    protected $fillable = [
        'workspace_id', 'store_id', 'external_id', 'platform', 'name', 'sku',
        'price', 'inventory_quantity', 'status', 'image_url', 'raw',
    ];

    protected $hidden = ['raw'];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'price' => 'decimal:2',
            'inventory_quantity' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(EcommerceStore::class, 'store_id');
    }
}
