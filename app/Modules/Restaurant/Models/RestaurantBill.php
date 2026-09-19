<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Modules\Shared\Models\Contact;
use Database\Factories\RestaurantBillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A processed POS order/bill, ingested from a `pending` `PosWebhookEvent`.
 *
 * `BelongsToWorkspace` applies here — unlike `PosConnection`/`PosWebhookEvent`
 * — because a row is only ever created by `ProcessPosWebhookEventJob` from an
 * already-resolved, already-authenticated event's `workspace_id`. There is no
 * pre-tenant-context lookup to protect, so scoping it normally is correct
 * from birth (see `WorkspaceScopeCoverageGuardTest`).
 *
 * `UNIQUE(connection_id, external_order_id)` is the business-level
 * idempotency the `pos_webhook_events` migration deferred to Phase 2: a
 * corrected/resent bill for the same order updates this row rather than
 * creating a duplicate.
 *
 * ⚠️ TIME AND STATUS FIELDS — read before ordering or filtering on any of them:
 *
 *  - `source_created_on_raw` is Petpooja's `Order.created_on` byte-for-byte. The
 *    documented samples carry NO timezone, so this is the only lossless record.
 *  - `placed_at` is that instant in UTC ONLY when a valid outlet timezone
 *    existed and the raw value parsed strictly; otherwise NULL. NULL means
 *    "unknown", never "now" and never "assume UTC".
 *  - `received_at` is when the first webhook carrying this bill arrived. It is
 *    the operational ordering key: always populated, unaffected by corrections.
 *  - `source_order_status` is `Order.status` as sent (documented: Success,
 *    Cancelled). A Cancelled bill is still a stored bill — anything that acts
 *    on a bill (a later messaging slice) MUST check this field first.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $connection_id
 * @property int|null $webhook_event_id
 * @property int|null $outlet_id
 * @property int|null $contact_id
 * @property string $provider
 * @property string $external_order_id
 * @property string|null $source_order_status
 * @property string|null $source_created_on_raw
 * @property string|null $customer_name
 * @property string|null $customer_phone_raw
 * @property string|null $total
 * @property string|null $core_total
 * @property string|null $discount_total
 * @property string|null $tax_total
 * @property array<int, mixed>|null $order_items
 * @property array<int, mixed>|null $taxes
 * @property array<int, mixed>|null $discounts
 * @property Carbon|null $placed_at
 * @property Carbon|null $received_at
 */
class RestaurantBill extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<RestaurantBillFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'connection_id',
        'webhook_event_id',
        'outlet_id',
        'contact_id',
        'provider',
        'external_order_id',
        'source_order_status',
        'source_created_on_raw',
        'customer_name',
        'customer_phone_raw',
        'total',
        'core_total',
        'discount_total',
        'tax_total',
        'order_items',
        'taxes',
        'discounts',
        'placed_at',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'order_items' => 'array',
            'taxes' => 'array',
            'discounts' => 'array',
            'placed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    protected static function newFactory(): RestaurantBillFactory
    {
        return RestaurantBillFactory::new();
    }

    /** @return BelongsTo<PosConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(PosConnection::class, 'connection_id');
    }

    /** @return BelongsTo<PosWebhookEvent, $this> */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(PosWebhookEvent::class, 'webhook_event_id');
    }

    /** @return BelongsTo<RestaurantOutlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(RestaurantOutlet::class, 'outlet_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
