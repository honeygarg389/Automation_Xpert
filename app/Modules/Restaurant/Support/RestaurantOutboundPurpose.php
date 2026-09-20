<?php

namespace App\Modules\Restaurant\Support;

/**
 * The only customer-communication purposes governed by Restaurant's future
 * outbound delivery paths. Keep this deliberately closed: a new purpose must
 * be reviewed with its own eligibility rules before it can be sent.
 */
final class RestaurantOutboundPurpose
{
    public const DIGITAL_BILL = 'digital_bill';

    public const FEEDBACK_REQUEST = 'feedback_request';

    /** @var list<string> */
    public const ALL = [
        self::DIGITAL_BILL,
        self::FEEDBACK_REQUEST,
    ];

    public static function isSupported(string $purpose): bool
    {
        return in_array($purpose, self::ALL, true);
    }
}
