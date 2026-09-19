<?php

namespace App\Modules\Restaurant\Exceptions;

use App\Modules\Restaurant\Jobs\ProcessPosWebhookEventJob;
use App\Modules\Restaurant\Services\PetpoojaOrderIngestionService;
use RuntimeException;

/**
 * Thrown by {@see PetpoojaOrderIngestionService} when a `pending`
 * `PosWebhookEvent` can NEVER be turned into a `RestaurantBill` because of
 * something wrong with the event itself: malformed structure, a missing,
 * blank or unusable `properties.Order.orderID`, an unresolvable connection, or
 * an `event_type` other than `orderdetails` reaching the job by mistake.
 *
 * ⚠️ THIS MEANS PERMANENT. {@see ProcessPosWebhookEventJob} catches it and
 * marks the event `failed` on its FIRST execution, with this exception's
 * message as the `failure_reason`, and does not retry — an identical payload
 * cannot succeed on attempt two. Only throw it for deterministic defects;
 * anything that might succeed later (a database outage, a deadlock) must be
 * left as an ordinary exception so it stays retryable.
 */
class UnprocessablePosWebhookEventException extends RuntimeException {}
