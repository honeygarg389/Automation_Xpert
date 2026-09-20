<?php

namespace App\Modules\Restaurant\Support;

/**
 * A side-effect-free eligibility result for a future Restaurant delivery job.
 *
 * It intentionally carries only audit-safe internal identifiers and policy
 * metadata. Phone numbers, message content, template parameters, POS payloads
 * and provider credentials never cross this boundary.
 */
final readonly class RestaurantOutboundDecision
{
    private function __construct(
        public bool $allowed,
        public string $purpose,
        public ?string $reasonCode,
        public ?int $workspaceId,
        public ?int $billId,
        public ?int $outletId,
        public ?int $contactId,
    ) {}

    public static function allow(
        string $purpose,
        int $workspaceId,
        int $billId,
        int $outletId,
        int $contactId,
    ): self {
        return new self(true, $purpose, null, $workspaceId, $billId, $outletId, $contactId);
    }

    public static function block(
        string $purpose,
        string $reasonCode,
        ?int $workspaceId = null,
        ?int $billId = null,
        ?int $outletId = null,
        ?int $contactId = null,
    ): self {
        return new self(false, $purpose, $reasonCode, $workspaceId, $billId, $outletId, $contactId);
    }
}
