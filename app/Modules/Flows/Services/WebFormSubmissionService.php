<?php

namespace App\Modules\Flows\Services;

use App\Modules\Flows\Models\FormSubmission;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Support\WorkspaceContext;

/** Writes a public web-form response through Slice 5's exact contact/trigger seams. */
class WebFormSubmissionService
{
    public function __construct(
        private readonly FlowSubmissionContactEnricher $contacts,
        private readonly FlowSubmissionTriggerDispatcher $triggers,
    ) {}

    /** @param array<string, mixed> $answers */
    public function store(WhatsappFlow $flow, array $answers, ?string $ipAddress, ?string $userAgent): FormSubmission
    {
        return WorkspaceContext::for($flow->workspace_id, function () use ($flow, $answers, $ipAddress, $userAgent): FormSubmission {
            $contact = $this->contacts->enrich($flow->workspace_id, $answers);

            $submission = FormSubmission::create([
                'workspace_id' => $flow->workspace_id,
                'whatsapp_flow_id' => $flow->id,
                'contact_id' => $contact?->id,
                'source' => FormSubmission::SOURCE_WEB_FORM,
                'answers' => $answers,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            $this->triggers->dispatch($flow, $submission);

            return $submission;
        });
    }
}
