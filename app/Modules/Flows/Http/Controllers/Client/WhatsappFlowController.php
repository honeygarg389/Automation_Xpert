<?php

namespace App\Modules\Flows\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Flows\Models\FormSubmission;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Flows\Services\WhatsappFlowJsonCompiler;
use App\Modules\Flows\Services\WhatsappFlowMetaSyncService;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Services\ContactService;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Support\PhoneNumber;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Client authoring surface for static Flow definitions. No Meta API is called here. */
class WhatsappFlowController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('client/Flows/Index', [
            'flows' => WhatsappFlow::query()
                ->withCount('submissions')
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (WhatsappFlow $flow) => $this->summary($flow))
                ->values(),
            'categories' => WhatsappFlow::CATEGORIES,
            'statuses' => WhatsappFlow::STATUSES,
        ]);
    }

    public function edit(WhatsappFlow $flow): Response
    {
        return Inertia::render('client/Flows/Builder', [
            'flow' => $this->summary($flow),
            'categories' => WhatsappFlow::CATEGORIES,
            'fieldTypes' => WhatsappFlowJsonCompiler::FIELD_TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, false);
        $flow = WhatsappFlow::create([
            ...$data,
            'workspace_id' => $this->workspaceId($request),
            'screens' => $data['screens'] ?? $this->defaultScreens(),
            'submit_settings' => $data['submit_settings'] ?? [
                'button_text' => 'Submit',
                'success_message' => 'Thank you. Your response has been submitted.',
            ],
        ]);

        return to_route('client.flows.edit', $flow)->with('success', 'Flow created.');
    }

    public function update(Request $request, WhatsappFlow $flow): RedirectResponse
    {
        $flow->update($this->validated($request, true));

        return back()->with('success', 'Flow saved.');
    }

    /**
     * Section G — state-machine-aware removal. See
     * WhatsappFlowMetaSyncService::removeFromMeta() for the full branching
     * (never-synced/synced-draft/published) and why each is a genuinely
     * different Graph API call, or none at all.
     *
     * On failure, nothing local changes here either — no delete() call —
     * so a Meta-side failure never leaves the local row claiming an outcome
     * ("deleted") that did not actually happen on Meta.
     */
    public function destroy(WhatsappFlow $flow, WhatsappFlowMetaSyncService $sync): RedirectResponse
    {
        $result = $sync->removeFromMeta($flow);

        if (! $result['success']) {
            return back()->with('error', $result['message']);
        }

        // Deprecating a PUBLISHED Flow is terminal on Meta's side but is NOT
        // a local delete — the row and its submission history stay, now
        // reflecting meta_sync_status = deprecated (already updated by
        // removeFromMeta()). Only the other two outcomes actually remove the
        // local row.
        if ($result['action'] === 'meta_deprecate') {
            return back()->with('success', $result['message']);
        }

        $flow->delete();

        return to_route('client.flows.index')->with('success', $result['message']);
    }

    /**
     * Section H — Duplicate: the only path to modifying a Published Flow
     * (Section F), and generically useful for any Flow. Copies the
     * authoring content only; meta_flow_id/meta_sync_status/web form
     * settings/submission-limit settings are deliberately NOT copied — a
     * duplicate starts exactly like a genuinely new Flow: an unconnected
     * local Draft, not sharing the original's Meta link, public web-form
     * link (public_slug is UNIQUE — it could not be copied even if wanted),
     * or submission cap.
     *
     * Refinement — a source that is currently PUBLISHED on Meta gets a real
     * Meta-side clone attached to the new copy (WhatsappFlowMetaSyncService
     * ::cloneOnMeta(), Meta's clone_flow_id pattern), so the duplicate
     * inherits Meta's own lineage rather than being a pure local copy that
     * only happens to share the same JSON. A Draft source (never published)
     * has no meaningful Meta-side identity to clone from, so it keeps the
     * unconnected-local-copy behavior exactly as before.
     *
     * Section D/E (imported-flow round-trip fix) — `meta_passthrough` IS
     * copied, unlike meta_flow_id/meta_sync_status: it is schema-preservation
     * metadata needed for a VALID recompile (Meta's data-exchange/routing
     * keys the visual builder doesn't edit), not a Meta-side connection —
     * copying it changes nothing about which Meta object (if any) the
     * duplicate is linked to.
     */
    public function duplicate(Request $request, WhatsappFlow $flow, WhatsappFlowMetaSyncService $sync): RedirectResponse
    {
        $copy = WhatsappFlow::create([
            'workspace_id' => $this->workspaceId($request),
            'name' => $flow->name.' (Copy)',
            'description' => $flow->description,
            'category' => $flow->category,
            'status' => WhatsappFlow::STATUS_DRAFT,
            'screens' => $flow->screens,
            'submit_settings' => $flow->submit_settings,
            'meta_passthrough' => $flow->meta_passthrough,
            // Carried, unlike meta_flow_id/meta_sync_status. The copy holds the SAME
            // placeholder screens, and when the source is Published it is also given
            // a Meta-side clone of the REAL content just below — so an unguarded copy
            // is exactly a placeholder waiting to be synced over its own clone.
            'import_unsupported_reason' => $flow->import_unsupported_reason,
        ]);

        if ($flow->meta_flow_id && $flow->meta_sync_status === WhatsappFlow::META_SYNC_STATUS_PUBLISHED) {
            $cloneResult = $sync->cloneOnMeta($copy, $flow->meta_flow_id);
            if ($cloneResult['success']) {
                return to_route('client.flows.edit', $copy)->with('success', $cloneResult['message']);
            }

            return to_route('client.flows.edit', $copy)->with('success', 'Flow duplicated locally. '.$cloneResult['message']);
        }

        return to_route('client.flows.edit', $copy)->with('success', 'Flow duplicated.');
    }

    public function preview(WhatsappFlow $flow, WhatsappFlowJsonCompiler $compiler): JsonResponse
    {
        return response()->json($compiler->compile($flow));
    }

    /**
     * "Sync Status" — the bulk RECONCILE action. Only ever touches Flows this
     * workspace already knows about (whereNotNull('meta_flow_id')): it pulls
     * each one's current content down from Meta, overwriting local screens.
     * Renamed from "Sync Meta Flows" because that name now belongs to
     * importPicker()/import() below — the actual "bring in Flows Meta has
     * that we don't" feature this workspace never had until now.
     */
    public function syncStatus(WhatsappFlowMetaSyncService $sync): RedirectResponse
    {
        $summary = $sync->pullAllFromMeta(WhatsappFlow::query()
            ->whereNotNull('meta_flow_id')
            ->get());

        return back()->with(
            'success',
            sprintf('Sync status complete: %d updated, %d unchanged, %d failed.', $summary['updated'], $summary['unchanged'], $summary['failed'])
        );
    }

    /**
     * Section E / Task 3 — the dashboard's single "Sync from Meta" button.
     * Distinct from syncStatus() above (refresh-only) and
     * importPicker()/import() below (manual picker) — both stay reachable
     * unchanged underneath this — this does the whole reconcile in one call
     * via WhatsappFlowMetaSyncService::syncAllFromMeta() and reports a
     * single concrete summary through the same flash-banner convention
     * every other action on this page already uses.
     */
    public function syncFromMeta(Request $request, WhatsappFlowMetaSyncService $sync): RedirectResponse
    {
        $summary = $sync->syncAllFromMeta($this->workspaceId($request));
        $message = sprintf('%d updated, %d imported, %d errors.', $summary['updated'], $summary['imported'], $summary['errors']);

        return back()->with($summary['errors'] > 0 ? 'error' : 'success', $message);
    }

    /** The picker's candidate list — Meta Flows with no local record yet. */
    public function importPicker(WhatsappFlowMetaSyncService $sync, Request $request): JsonResponse
    {
        try {
            return response()->json(['flows' => $sync->listImportableFlows($this->workspaceId($request))]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function import(Request $request, WhatsappFlowMetaSyncService $sync): RedirectResponse
    {
        $data = $request->validate([
            'meta_flow_ids' => ['required', 'array', 'min:1'],
            'meta_flow_ids.*' => ['required', 'string'],
        ]);

        $workspaceId = $this->workspaceId($request);
        $imported = 0;
        $failed = 0;

        foreach ($data['meta_flow_ids'] as $metaFlowId) {
            try {
                $sync->importFlow($workspaceId, $metaFlowId);
                $imported++;
            } catch (RuntimeException) {
                $failed++;
            }
        }

        $message = $failed === 0
            ? sprintf('%d Flow%s imported.', $imported, $imported === 1 ? '' : 's')
            : sprintf('%d Flow%s imported, %d failed.', $imported, $imported === 1 ? '' : 's', $failed);

        return back()->with($failed === 0 ? 'success' : 'error', $message);
    }

    public function submissions(Request $request, WhatsappFlow $flow): Response
    {
        $search = trim((string) $request->string('search'));
        $query = FormSubmission::query()
            ->where('whatsapp_flow_id', $flow->id)
            ->with('contact')
            ->latest();

        if ($search !== '') {
            $query->whereHas('contact', fn ($contacts) => $contacts
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('phone_e164', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        $submissions = $query->paginate(25)->through(
            fn (FormSubmission $submission): array => $this->submissionSummary($submission)
        );

        return Inertia::render('client/Flows/Submissions', [
            'flow' => $this->summary($flow),
            'submissions' => $submissions,
            'filters' => ['search' => $search],
        ]);
    }

    public function sync(WhatsappFlow $flow, WhatsappFlowMetaSyncService $sync): RedirectResponse
    {
        $result = $sync->syncToMeta($flow);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function publish(WhatsappFlow $flow, WhatsappFlowMetaSyncService $sync): RedirectResponse
    {
        if (! $flow->meta_flow_id) {
            return back()->with('error', 'Sync this Flow to Meta before publishing it.');
        }

        $result = $sync->publishOnly($flow);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Section A — the single-click "Publish to Meta" action: validate, sync,
     * stop at Meta's validation errors, publish. See
     * WhatsappFlowMetaSyncService::publishToMeta() for the full chain and why
     * it composes syncToMeta()/publishOnly() rather than duplicating either.
     * The plain sync() and publish() actions above stay reachable unchanged
     * underneath this — "Sync Draft to Meta" still calls sync() directly.
     */
    public function publishToMeta(WhatsappFlow $flow, WhatsappFlowMetaSyncService $sync): RedirectResponse
    {
        $result = $sync->publishToMeta($flow);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Section F — per-flow "Sync from Meta" for a Published flow: pulls
     * Meta's current content down (the reverse direction of sync()/publish(),
     * which push local content up — pointless once published, since a
     * published Flow's assets are immutable on Meta's own side anyway).
     * Reuses pullFromMeta() exactly as the bulk syncStatus() action already
     * does for every linked Flow, just scoped to this one.
     */
    public function pull(WhatsappFlow $flow, WhatsappFlowMetaSyncService $sync): RedirectResponse
    {
        $outcome = $sync->pullFromMeta($flow);

        return match ($outcome) {
            'updated' => back()->with('success', 'Flow refreshed from Meta.'),
            'unchanged' => back()->with('success', 'Flow is already up to date with Meta.'),
            'failed' => back()->with('error', $flow->fresh()->meta_sync_error ?? 'Could not refresh this Flow from Meta.'),
        };
    }

    /**
     * Sends this Flow, for real, to one phone number — the builder's "test
     * send" action. Reuses AutomationEngine::sendFlowTestMessage(), which
     * itself reuses the exact interactive-payload construction and send path
     * the whatsapp_form automation node uses (see that method's docblock),
     * so a successful test send is genuine evidence the automation-triggered
     * path works too, and the message appears in the real Inbox conversation
     * for this contact — not a synthetic preview.
     */
    public function testSend(Request $request, WhatsappFlow $flow, AutomationEngine $automationEngine, ContactService $contacts): JsonResponse
    {
        if (! $flow->meta_flow_id) {
            return response()->json(['message' => 'Sync this Flow to Meta before sending a test.'], 422);
        }

        $waba = WhatsappBusinessAccount::query()
            ->where('workspace_id', $flow->workspace_id)
            ->where('status', 'active')
            ->first();
        if (! $waba) {
            return response()->json(['message' => 'Connect an active WhatsApp Business Account before sending a test message.'], 422);
        }

        $data = $request->validate(['phone_number' => ['required', 'string', 'max:32']]);

        // The reference UI's own hint asks for digits only, with the country
        // code but no leading "+" — but a stray "+" a user types anyway must
        // not become the malformed "++91…" PhoneNumber::normalizeForImport()
        // would then reject, so one is stripped before one is added back.
        // With no explicit country selector on this modal, prefixing "+" and
        // routing through the explicit-E164 path is the only one of
        // PhoneNumber's two paths that fits a bare "country code + number"
        // string — the local-number path requires a $defaultCountry this
        // modal does not collect.
        $raw = ltrim(trim($data['phone_number']), '+');
        $normalized = PhoneNumber::normalizeForImport('+'.$raw, null);
        if ($normalized['error'] !== null) {
            return response()->json(['message' => $normalized['error']], 422);
        }
        $phoneE164 = $normalized['phone'];

        // find-or-create — but ONLY the create half goes through upsert().
        // upsert() merges its $data into an EXISTING row on the update path,
        // so calling it unconditionally would silently overwrite a real
        // contact's own name/source with this action's test-send values.
        // Reusing an already-known contact must leave it untouched.
        $contact = Contact::query()
            ->where('workspace_id', $flow->workspace_id)
            ->where('phone_e164', $phoneE164)
            ->first();
        if (! $contact) {
            $contact = $contacts->upsert($flow->workspace_id, [
                'phone_e164' => $phoneE164,
                'first_name' => 'Test Contact',
                // Distinct from organic sources (manual/whatsapp_inbound/…)
                // so a test-send contact is never mistaken for a real lead.
                'source' => 'flow_test_send',
            ], dispatchCreatedEvent: false);
        }

        $flowToken = 'flow_test_'.Str::random(10);
        $result = $automationEngine->sendFlowTestMessage($flow->workspace_id, $contact, $flow->meta_flow_id, $flow->name, $flowToken);

        if ($result['status'] !== 'ok') {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json(['message' => 'Test message sent to '.$phoneE164.'.']);
    }

    /**
     * Enabling is idempotent on the slug: a flow that already has one (from a
     * prior enable, even if since disabled) keeps it — only a flow that has
     * NEVER been enabled gets a freshly generated one. Re-enabling must not
     * silently invalidate a link an owner already shared; that is what
     * regenerateWebForm() exists for, as an explicit, separate action.
     */
    public function enableWebForm(WhatsappFlow $flow): RedirectResponse
    {
        $flow->update([
            'web_form_enabled' => true,
            'public_slug' => $flow->public_slug ?? WhatsappFlow::generatePublicSlug(),
        ]);

        return back()->with('success', 'Web form enabled.');
    }

    /**
     * The slug is deliberately NOT cleared here — see enableWebForm()'s
     * docblock. Disabling only takes the public route away; it does not
     * discard the identity of the link, so re-enabling restores the exact
     * same URL an owner may have already shared or printed.
     */
    public function disableWebForm(WhatsappFlow $flow): RedirectResponse
    {
        $flow->update(['web_form_enabled' => false]);

        return back()->with('success', 'Web form disabled.');
    }

    /**
     * Deliberately invalidates the previous link — this is the ONLY action
     * that changes public_slug once set. An owner reaches for this after a
     * link leaked somewhere it shouldn't have.
     */
    public function regenerateWebFormSlug(WhatsappFlow $flow): RedirectResponse
    {
        $flow->update(['public_slug' => WhatsappFlow::generatePublicSlug()]);

        return back()->with('success', 'Web form link regenerated. The previous link no longer works.');
    }

    public function updateWebFormRecaptcha(Request $request, WhatsappFlow $flow): RedirectResponse
    {
        $flow->update($request->validate([
            'recaptcha_enabled' => ['required', 'boolean'],
        ]));

        return back()->with('success', 'reCAPTCHA setting updated.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $withScreens): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'in:'.implode(',', WhatsappFlow::CATEGORIES)],
            'status' => ['required', 'string', 'in:'.implode(',', WhatsappFlow::STATUSES)],
            'submit_settings' => ['nullable', 'array'],
            'submit_settings.button_text' => ['nullable', 'string', 'max:35'],
            'submit_settings.success_message' => ['nullable', 'string', 'max:1024'],
            'max_submissions' => ['nullable', 'integer', 'min:1'],
            'limit_error_message' => ['nullable', 'string', 'max:255'],
        ];

        $rules += [
            'screens' => [$withScreens ? 'required' : 'nullable', 'array', 'min:1'],
            'screens.*.id' => ['required_with:screens', 'string', 'max:64'],
            'screens.*.title' => ['required_with:screens', 'string', 'max:128'],
            'screens.*.fields' => ['required_with:screens', 'array', 'min:1'],
            'screens.*.fields.*.id' => ['required_with:screens', 'string', 'max:64'],
            'screens.*.fields.*.type' => ['required_with:screens', 'string', 'in:'.implode(',', WhatsappFlowJsonCompiler::FIELD_TYPES)],
            'screens.*.fields.*.label' => ['required_with:screens', 'string', 'max:256'],
            'screens.*.fields.*.name' => ['nullable', 'string', 'max:64'],
            'screens.*.fields.*.required' => ['required_with:screens', 'boolean'],
            'screens.*.fields.*.helper_text' => ['nullable', 'string', 'max:512'],
            'screens.*.fields.*.options' => ['nullable', 'array'],
            'screens.*.fields.*.step' => ['required_with:screens', 'integer', 'min:1'],
            'screens.*.fields.*.order' => ['required_with:screens', 'integer', 'min:1'],
        ];

        return $request->validate($rules);
    }

    private function workspaceId(Request $request): int
    {
        return (int) (WorkspaceContext::id() ?? $request->user()->workspace_id);
    }

    /**
     * @return array<string,mixed>
     *
     * Includes the full `screens` array (not just field_count) so the list
     * page's Info modal (Form Fields tab) can render from data already on the
     * page — no second request per flow. Fine at this workspace's expected
     * flow counts (index() has never paginated); a workspace with hundreds of
     * Flows would need to reconsider this, but none does today.
     */
    private function summary(WhatsappFlow $flow): array
    {
        return [
            'id' => $flow->id,
            'uuid' => $flow->uuid,
            'name' => $flow->name,
            'description' => $flow->description,
            'category' => $flow->category,
            'status' => $flow->status,
            'screens' => $flow->screens,
            'submit_settings' => $flow->submit_settings,
            'meta_flow_id' => $flow->meta_flow_id,
            'meta_sync_status' => $flow->meta_sync_status,
            'meta_validation_errors' => $flow->meta_validation_errors,
            'meta_sync_error' => $flow->meta_sync_error,
            'import_unsupported_reason' => $flow->import_unsupported_reason,
            // The refusal wording has ONE definition (the service's constant); the pages
            // display it rather than carrying a second copy that could drift.
            'import_guard_message' => $flow->isLossyImport() ? WhatsappFlowMetaSyncService::LOSSY_IMPORT_MESSAGE : null,
            'field_count' => collect($flow->screens)->sum(fn (array $screen) => count($screen['fields'])),
            'step_count' => count($flow->screens),
            'submissions_count' => $flow->submissions_count ?? $flow->submissions()->count(),
            'created_at' => $flow->created_at?->toISOString(),
            'updated_at' => $flow->updated_at?->toISOString(),
            'web_form_enabled' => $flow->web_form_enabled,
            'public_slug' => $flow->public_slug,
            'recaptcha_enabled' => $flow->recaptcha_enabled,
            'max_submissions' => $flow->max_submissions,
            'limit_error_message' => $flow->limit_error_message,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function defaultScreens(): array
    {
        return [[
            'id' => 'step_1',
            'title' => 'Your details',
            'fields' => [[
                'id' => 'name', 'type' => 'text', 'label' => 'Name', 'name' => 'name',
                'required' => true, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1,
            ]],
        ]];
    }

    /**
     * @return array{uuid:string,submitted_at:string|null,contact:array{name:string,phone:string|null,email:string|null}|null,answers:array<string,mixed>,answer_preview:string}
     */
    private function submissionSummary(FormSubmission $submission): array
    {
        $contact = $submission->contact;

        return [
            'uuid' => $submission->uuid,
            'submitted_at' => $submission->created_at?->toISOString(),
            'contact' => $contact ? [
                'name' => $contact->full_name,
                'phone' => $contact->phone_e164,
                'email' => $contact->email,
            ] : null,
            'answers' => $submission->answers,
            'answer_preview' => collect($submission->answers)
                ->take(3)
                ->map(fn (mixed $value, string $key): string => $key.': '.(is_array($value) ? implode(', ', $value) : (string) $value))
                ->implode(' · '),
        ];
    }
}
