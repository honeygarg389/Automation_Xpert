<?php

namespace App\Modules\Flows\Services;

/** @phpstan-type FlowBody array<string, mixed> */
final readonly class DecryptedFlowRequest
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public array $body,
        public string $aesKey,
        public string $initialVector,
    ) {}
}
