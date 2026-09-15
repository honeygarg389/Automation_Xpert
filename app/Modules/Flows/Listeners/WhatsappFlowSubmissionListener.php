<?php

namespace App\Modules\Flows\Listeners;

use App\Listeners\AutomationTriggerListener;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Models\AutomationRunLog;
use App\Modules\Flows\Events\WhatsappFlowSubmitted;
use App\Modules\Flows\Models\FormSubmission;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Flows\Services\FlowSubmissionContactEnricher;
use App\Support\WorkspaceContext;

/**
 * Turns a parsed WhatsApp nfm_reply into the durable submission ledger and its
 * one downstream automation trigger. The event is synchronous so inbound
 * handling retains the workspace context set by WhatsappDriver.
 */
class WhatsappFlowSubmissionListener
{
    public function __construct(
        private readonly FlowSubmissionContactEnricher $contacts,
        private readonly AutomationTriggerListener $triggers,
    ) {}

    public function handle(WhatsappFlowSubmitted $event): void
    {
        WorkspaceContext::for($event->workspaceId, function () use ($event): void {
            $run = $this->correlateRun($event->workspaceId, $event->contactId, $event->flowToken);
            $flow = $this->flowForRun($run);
            $contact = $this->contacts->enrich($event->workspaceId, $event->answers, $event->contactId);

            $submission = FormSubmission::create([
                'workspace_id' => $event->workspaceId,
                'whatsapp_flow_id' => $flow?->id,
                'contact_id' => $contact?->id,
                'source' => FormSubmission::SOURCE_WHATSAPP_FLOW,
                'answers' => $event->answers,
                'flow_token' => $event->flowToken,
                'automation_run_id' => $run?->id,
            ]);

            if ($contact !== null) {
                $this->triggers->fireFormSubmitted(
                    $event->workspaceId,
                    $contact->id,
                    $this->triggerContext($flow, $submission)
                );
            }
        });
    }

    private function correlateRun(int $workspaceId, ?int $contactId, string $flowToken): ?AutomationRun
    {
        $query = AutomationRun::with('automation')
            ->whereHas('automation', fn ($q) => $q->where('workspace_id', $workspaceId));

        // Default tokens encode the run ID, so this is an exact, validated match.
        if (preg_match('/^flow_(\d+)$/D', $flowToken, $matches) === 1) {
            return $query->whereKey((int) $matches[1])->first();
        }

        // Custom tokens are saved when the form node sends. They are only
        // meaningful for the sender that received them: never correlate a
        // response to another contact's run, even if a token was reused.
        if ($contactId === null) {
            return null;
        }

        return $query
            ->where('contact_id', $contactId)
            ->whereIn('status', ['waiting', 'running', 'completed'])
            ->where('context->_whatsapp_flow_token', $flowToken)
            ->latest('id')
            ->first();
    }

    private function flowForRun(?AutomationRun $run): ?WhatsappFlow
    {
        if ($run === null || $run->automation === null) {
            return null;
        }

        $log = AutomationRunLog::where('run_id', $run->id)
            ->where('node_type', 'whatsapp_form')
            ->latest('id')
            ->first();
        if ($log === null) {
            return null;
        }

        $node = collect($run->automation->nodes)
            ->first(fn (array $node): bool => ($node['id'] ?? null) === $log->node_id);
        $nodeData = is_array($node['data'] ?? null) ? $node['data'] : [];
        $metaFlowId = $nodeData['flow_id'] ?? null;

        return is_string($metaFlowId) && $metaFlowId !== ''
            ? WhatsappFlow::where('meta_flow_id', $metaFlowId)->first()
            : null;
    }

    /** @return array<string, string> */
    private function triggerContext(?WhatsappFlow $flow, FormSubmission $submission): array
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
            'flow_name' => $flow === null ? 'WhatsApp Flow' : $flow->name,
            'submitted_at' => $submission->created_at->toIso8601String(),
        ]);
    }
}
