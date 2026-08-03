<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'webhook_endpoint_id',
        'event',
        'payload',
        'response_status',
        'response_body',
        'attempts',
        'delivered_at',
        'next_retry_at',
    ];

    /**
     * The receiver's response body is retained for internal debugging but must
     * never reach the customer: the endpoint URL is customer-supplied, so
     * echoing the response back would turn a blocked request into a readable
     * SSRF primitive. Customers see status, timing and success/failure instead.
     */
    protected $hidden = [
        'response_body',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'delivered_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function isSuccessful(): bool
    {
        return $this->response_status >= 200 && $this->response_status < 300;
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
