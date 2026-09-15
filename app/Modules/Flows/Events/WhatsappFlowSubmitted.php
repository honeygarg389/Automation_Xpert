<?php

namespace App\Modules\Flows\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Parsed Flow completion, emitted after the inbound WhatsApp message has persisted. */
class WhatsappFlowSubmitted
{
    use Dispatchable, SerializesModels;

    /** @param array<string, mixed> $answers */
    public function __construct(
        public readonly int $workspaceId,
        public readonly ?int $contactId,
        public readonly string $flowToken,
        public readonly array $answers,
    ) {}
}
