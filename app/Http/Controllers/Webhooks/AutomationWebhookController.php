<?php

namespace App\Http\Controllers\Webhooks;

use App\Events\AutomationWebhookReceived;
use App\Modules\Automation\Models\Automation;
use App\Modules\Shared\Models\Contact;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AutomationWebhookController
{
    public function receive(Request $request, string $triggerToken): JsonResponse
    {
        $automation = Automation::where('trigger_token', $triggerToken)
            ->where('status', 'active')
            ->first();

        if (! $automation) {
            return response()->json(['error' => 'Automation not found or inactive.'], 404);
        }

        $payload = $request->all();

        // Phase 0, slice 6 PREREQUISITE. This is an UNAUTHENTICATED webhook —
        // `webhooks/automation/{trigger_token}` — so there is no user and
        // WorkspaceContext resolves to null. With Contact scoped and the scope
        // failing closed, both lookups below would return null, `$contactId`
        // would stay null, and the automation would fire WITHOUT its contact.
        // No exception, no failed job: a personalised automation quietly running
        // unpersonalised.
        //
        // It is a CONTROLLER, so neither the job guard nor the command guard
        // covers it — which is exactly why it was annotated in the coverage
        // guard's PENDING list rather than left to be discovered.
        //
        // No bypass is needed: the workspace is known. `automations.trigger_token`
        // is UNIQUE, so the automation identifies its own tenant, and the
        // explicit `where('workspace_id', …)` below now agrees with the scope
        // instead of being ANDed against a null one.
        $contactId = WorkspaceContext::for((int) $automation->workspace_id, function () use ($payload, $automation) {
            if (isset($payload['email'])) {
                return Contact::where('workspace_id', $automation->workspace_id)
                    ->where('email', $payload['email'])
                    ->first()?->id;
            }

            if (isset($payload['phone'])) {
                return Contact::where('workspace_id', $automation->workspace_id)
                    ->where('phone_e164', $payload['phone'])
                    ->first()?->id;
            }

            return null;
        });

        AutomationWebhookReceived::dispatch($automation->id, $payload, $contactId);

        return response()->json(['status' => 'accepted'], 202);
    }
}
