<?php

namespace App\Modules\Flows\Services;

use App\Listeners\AutomationTriggerListener;
use App\Modules\Flows\Models\FormSubmission;
use App\Modules\Flows\Models\WhatsappFlow;

/**
 * The one trigger path shared by WhatsApp Flow replies and public web forms.
 * A submission without a resolved contact remains durable, but cannot start a
 * contact-owned automation run.
 */
class FlowSubmissionTriggerDispatcher
{
    public function __construct(private readonly AutomationTriggerListener $triggers) {}

    public function dispatch(?WhatsappFlow $flow, FormSubmission $submission): void
    {
        if ($submission->contact_id === null) {
            return;
        }

        $this->triggers->fireFormSubmitted(
            $submission->workspace_id,
            $submission->contact_id,
            $this->context($flow, $submission),
        );
    }

    /** @return array<string, string> */
    private function context(?WhatsappFlow $flow, FormSubmission $submission): array
    {
        $answers = [];
        foreach ($submission->answers as $key => $value) {
            if ($key === '') {
                continue;
            }

            if (is_scalar($value)) {
                $answers[$key] = (string) $value;
            } elseif ($value !== null) {
                $answers[$key] = json_encode($value, JSON_THROW_ON_ERROR);
            }
        }

        return array_merge($answers, [
            'flow_name' => $flow->name ?? 'WhatsApp Flow',
            'submitted_at' => $submission->created_at->toIso8601String(),
            'source' => $submission->source,
        ]);
    }
}
