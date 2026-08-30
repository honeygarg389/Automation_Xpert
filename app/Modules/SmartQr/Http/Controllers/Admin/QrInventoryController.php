<?php

namespace App\Modules\SmartQr\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Modules\SmartQr\Jobs\GenerateQrExportJob;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Services\SmartQrDeletability;
use App\Modules\SmartQr\Services\SmartQrImageRenderer;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §5 — QR inventory.
 *
 * ⚠️ QUERIES `SmartQrCode` DIRECTLY, and that is legitimate exactly here.
 *
 * The model is lifecycle-owned and carries no workspace scope, so a raw query in
 * customer-facing code would be a silent cross-tenant read — which is why
 * `SmartQrAccessGuardTest` fails the build on one. The admin namespace is
 * allow-listed because unassigned inventory is precisely what this screen exists
 * to manage: a scoped query here would hide every code nobody holds yet.
 *
 * Slice 1 pre-authorised this directory in that guard's ALLOWED list, before the
 * controller existed.
 */
class QrInventoryController extends Controller
{
    private const EXPORT_DIR = 'smartqr-exports';

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:64'],
            'batch_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(SmartQrStatus::CODE_STATUSES)],
            'assignment' => ['nullable', Rule::in(['assigned', 'unassigned'])],
            'workspace_id' => ['nullable', 'integer'],
            'qr_type' => ['nullable', 'string', 'max:32'],
            'printed' => ['nullable', Rule::in(['yes', 'no'])],
        ]);

        // ⚠️ The scope must come off every assignment subquery below.
        //
        // SmartQrAssignment uses BelongsToWorkspace, and an admin request has no
        // workspace context — so inside a whereHas the scope ANDs 1=0 onto the
        // subquery and it matches NOTHING. Every "assigned" filter would return
        // an empty set for codes that are genuinely assigned.
        //
        // This is the fail-CLOSED direction of hazard H-2, and it is exactly how
        // the slice-1 canary caught SmartQrAccess returning 0.
        $unscoped = fn ($q) => $q->withoutGlobalScope(WorkspaceScope::class);

        $codes = SmartQrCode::query()
            ->with([
                'batch:id,batch_number,batch_name,prefix',
                'currentAssignment' => $unscoped,
                'currentAssignment.workspace:id,name,client_id',
            ])
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('serial_number', 'like', "%{$v}%"))
            ->when($filters['batch_id'] ?? null, fn ($q, $v) => $q->where('batch_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(($filters['printed'] ?? null) === 'yes', fn ($q) => $q->whereNotNull('printed_at'))
            ->when(($filters['printed'] ?? null) === 'no', fn ($q) => $q->whereNull('printed_at'))
            // ⚠️ R-10 — "assigned" is DERIVED from the current-assignment index,
            // never read from a status column. There is no `assigned` value in
            // SmartQrStatus::CODE_STATUSES to read even if someone tried.
            ->when(($filters['assignment'] ?? null) === 'assigned',
                fn ($q) => $q->whereHas('currentAssignment', $unscoped))
            ->when(($filters['assignment'] ?? null) === 'unassigned',
                fn ($q) => $q->whereDoesntHave('currentAssignment', $unscoped))
            ->when($filters['workspace_id'] ?? null, fn ($q, $v) => $q->whereHas(
                'currentAssignment',
                fn ($a) => $unscoped($a)->where('workspace_id', $v)
            ))
            ->when($filters['qr_type'] ?? null, fn ($q, $v) => $q->whereHas(
                'currentAssignment',
                fn ($a) => $unscoped($a)->where('qr_type', $v)
            ))
            ->orderBy('serial_number')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/SmartQr/Inventory/Index', [
            'codes' => $codes,
            'filters' => $filters,
            'batches' => SmartQrBatch::query()
                ->orderByDesc('id')
                ->get(['id', 'batch_number', 'batch_name']),
            'statuses' => SmartQrStatus::CODE_STATUSES,

            // ⚠️ The export size note is driven by MEASURED bytes, and it must
            // be computed here rather than hard-coded in React: the figures
            // differ by ~100× depending on whether a logo is composited, which
            // React cannot know.
            //
            // ⚠️ CONSERVATIVE, AND DELIBERATELY NOT EXACT. The logo now belongs
            // to a BATCH, so a selection spanning batches can mix both branches
            // and no single figure is right. `exists()` over all batches means:
            // if ANY batch carries a logo, quote the larger number. This is a
            // hint for somebody deciding whether to wait, not a billing figure —
            // over-quoting a wait is a mild surprise, under-quoting it is an
            // admin cancelling a download that was nearly done.
            'exportBytesPerCode' => app(SmartQrImageRenderer::class)
                ->zippedBytesPerCode(SmartQrBatch::whereNotNull('logo_path')->exists()),
            'exportMaxCodes' => GenerateQrExportJob::MAX_CODES,
            'exportFormats' => GenerateQrExportJob::FORMATS,
            'readyExports' => $this->readyExports(),

            // ⚠️ ADDED IN SLICE 3b, and it is a gap 3a did not notice.
            //
            // The assignment modal's target picker is a WORKSPACE picker (R-1),
            // and nothing on this page supplied the list — 3a asserted the props
            // it returned rather than the props the screen needed, which is a
            // limitation of testing a controller without its consumer.
            //
            // Client name travels with it because admins navigate by
            // organisation even though the value posted is the workspace id.
            // The label is built here, not in React, so "which client owns this
            // workspace" has one definition.
            //
            // ⚠️ Unpaginated, and that is a known ceiling: at a few thousand
            // workspaces this payload becomes the largest thing on the page and
            // should become a searchable async picker — the same call
            // `assignments.options` already makes per workspace. Left simple
            // deliberately rather than building a search endpoint no installation
            // needs yet.
            'workspaces' => Workspace::query()
                ->with('client:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'client_id'])
                ->map(fn (Workspace $w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'client_name' => $w->client?->name,
                ])
                ->values(),
        ]);
    }

    /**
     * One code's detail page. §5.
     *
     * ⚠️ BOUND ON serial_number — SmartQrCode::getRouteKeyName() returns it, not
     * `uuid` (batches) and not `id`. The serial is what is PRINTED, so it is what
     * an operator holding a sticker can type into a URL.
     *
     * ⚠️ THE ID IS PASSED TO THE PAGE ANYWAY, and that is not redundant. Every
     * action this page offers — Change Stage, Assign — reuses the existing BULK
     * endpoints, whose validation is `exists:smart_qr_codes,id`. The serial gets
     * you the page; the id is what the actions post.
     *
     * ⚠️ NAME / QR TYPE / MESSAGE / DESTINATION PHONE ARE NOT ON THE CODE. They
     * live on the ASSIGNMENT, deliberately: a code is physical inventory that
     * outlives any one tenancy, and a recycled sticker must not carry the
     * previous customer's label. That is the same reasoning that removed
     * `qr_type` from batch creation. The page therefore renders them only when
     * `current_assignment` exists, rather than showing empty fields that imply
     * inventory-level data which has nowhere to be stored.
     *
     * ⚠️ THE SCOPE REMOVALS BELOW ARE MEASURED NO-OPS ON THIS ROUTE, AND THAT
     * IS RECORDED RATHER THAN ASSUMED — the same finding QrBatchController::show()
     * documents for its loadCount() closures.
     *
     * Mutation-tested against this action: deleting the closure on
     * `currentAssignment.channelAccount` changes NOTHING, because
     * WorkspaceScope::apply() returns before attaching a constraint when
     * `Auth::guard('admin')->check()` is true, and this route sits under
     * `auth:admin` with no other entry point.
     *
     * `workspace` is doubly inert: Workspace carries no global scope at all —
     * it IS the tenant — so there is nothing there to remove. Not written.
     *
     * ⚠️ WHAT IS LOAD-BEARING IS THE REMOVAL ON THE RELATION ITSELF,
     * SmartQrAssignment::channelAccount(). Reverting that one fails a test,
     * because the relation is also read outside an admin request (jobs, console,
     * direct model use) where the scope is live and resolves it to NULL —
     * "Destination Phone: —" reading as absent data rather than as a bug.
     *
     * The closure here is kept for the reason the batch controller keeps its
     * own: it costs nothing, and it is correct against a future caller that is
     * not admin-guard-only.
     */
    public function show(SmartQrCode $code): Response
    {
        $code->load([
            'batch:id,uuid,batch_number,batch_name,prefix',
            'currentAssignment' => fn ($q) => $q->withoutGlobalScope(WorkspaceScope::class),
            'currentAssignment.workspace',
            'currentAssignment.assignedUser',
            'currentAssignment.channelAccount' => fn ($q) => $q->withoutGlobalScope(WorkspaceScope::class),
        ]);

        $assignment = $code->currentAssignment;
        $channel = $assignment?->channelAccount;

        return Inertia::render('Admin/SmartQr/Inventory/Show', [
            'code' => [
                'id' => $code->id,
                'serial_number' => $code->serial_number,
                'status' => $code->status,
                'printed_at' => $code->printed_at,
                'created_at' => $code->created_at,

                // ⚠️ The PUBLIC scan URL, built from the token — never the token
                // on its own. It is the thing encoded in the artwork, and the
                // page offers it for copy-to-clipboard so an admin can test a
                // destination without a phone.
                'public_url' => route('smartqr.scan', ['token' => $code->public_token]),
            ],
            'batch' => $code->batch ? [
                'uuid' => $code->batch->uuid,
                'batch_number' => $code->batch->batch_number,
                'batch_name' => $code->batch->batch_name,
            ] : null,
            'currentAssignment' => $code->currentAssignment ? [
                'uuid' => $code->currentAssignment->uuid,
                'name' => $code->currentAssignment->name,
                'qr_type' => $code->currentAssignment->qr_type,
                'default_message' => $code->currentAssignment->default_message,
                'status' => $code->currentAssignment->status,
                'starts_at' => $code->currentAssignment->starts_at,
                'expires_at' => $code->currentAssignment->expires_at,
                'workspace_name' => $code->currentAssignment->workspace?->name,
                'assigned_user_name' => $code->currentAssignment->assignedUser?->name,

                // ⚠️ display_name first — channel_accounts has no plain phone
                // column, and phone_number_id is a Meta identifier, not a number
                // anybody can read off a screen. Falling back to it is better
                // than a dash when the account was created without a label.
                'destination_phone' => $channel ? ($channel->display_name ?? $channel->phone_number_id) : null,
            ] : null,
            'statuses' => SmartQrStatus::CODE_STATUSES,
            'exportFormats' => GenerateQrExportJob::FORMATS,
            'workspaces' => Workspace::query()
                ->with('client:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'client_id'])
                ->map(fn (Workspace $w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'client_name' => $w->client?->name,
                ])
                ->values(),
        ]);
    }

    /**
     * The QR artwork for one code, as SVG, for the detail page's preview.
     *
     * ⚠️ THE CUSTOMER-FACING preview() CANNOT BE REUSED, which is the whole
     * reason this exists. SmartQrCodeController::preview() resolves the code via
     * findForWorkspace() and 404s otherwise — so it refuses every code an admin
     * would want to look at: anything unassigned, and anything belonging to a
     * different tenant. This is the same eight lines with that scoping removed.
     *
     * ⚠️ The BATCH's logo, not the platform's, and null renders plain — the
     * preview must be the artwork the printer receives, or an admin approves one
     * thing and ships another.
     */
    public function preview(SmartQrCode $code, SmartQrImageRenderer $renderer): SymfonyResponse
    {
        $rendered = $renderer->svg(
            route('smartqr.scan', ['token' => $code->public_token]),
            $code->serial_number,
            $renderer->batchLogoPath($code->batch),
        );

        return response($rendered['data'], 200, [
            'Content-Type' => $rendered['mime'],
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * Download ONE code's artwork in one format.
     *
     * ⚠️ SYNCHRONOUS, AND THE BULK PIPELINE IS DELIBERATELY NOT USED. Generated
     * per request, not stored: a single code is cheap; only bulk needs the queue.
     * That is the same reasoning SmartQrCodeController::download() records, and
     * it is measured rather than assumed — one code with a batch logo renders in
     * 31 ms (SVG), 41 ms (PNG), 77 ms (PDF), against a batch export where every
     * format breached the 30 s limit at 1000 codes.
     *
     * So there is no job, no smart_qr_exports row and no ZIP here. All three
     * exist to solve chunking, progress and retrieval — none of which arises at
     * n=1, where the file is simply the response.
     */
    public function download(Request $request, SmartQrCode $code, SmartQrImageRenderer $renderer): SymfonyResponse
    {
        $format = (string) $request->query('format', 'svg');

        if (! in_array($format, GenerateQrExportJob::FORMATS, true)) {
            abort(422, 'Unsupported format.');
        }

        $rendered = $renderer->{$format}(
            route('smartqr.scan', ['token' => $code->public_token]),
            $code->serial_number,
            $renderer->batchLogoPath($code->batch),
        );

        return response($rendered['data'], 200, [
            'Content-Type' => $rendered['mime'],
            'Content-Disposition' => 'attachment; filename="'.$code->serial_number.'.'.$format.'"',
        ]);
    }

    /**
     * §5 bulk action — mark printed.
     *
     * ⚠️ A PHYSICAL transition, so it moves `codes.status` (R-10). It says
     * nothing about whether the code is assigned, and must not.
     */
    public function markPrinted(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code_ids' => ['required', 'array', 'min:1'],
            'code_ids.*' => ['integer', 'exists:smart_qr_codes,id'],
        ]);

        SmartQrCode::whereIn('id', $data['code_ids'])
            ->whereIn('status', [SmartQrStatus::CODE_GENERATED, SmartQrStatus::CODE_PRINTED])
            ->update(['status' => SmartQrStatus::CODE_PRINTED, 'printed_at' => now()]);

        return back()->with('success', __('Marked as printed.'));
    }

    /**
     * §5 bulk action — retire / mark damaged / mark lost.
     *
     * Restricted to the physical vocabulary by Rule::in, so no caller can post
     * `assigned` and create the second source of truth R-10 forbids.
     */
    public function changeStatus(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code_ids' => ['required', 'array', 'min:1'],
            'code_ids.*' => ['integer', 'exists:smart_qr_codes,id'],
            'status' => ['required', Rule::in(SmartQrStatus::CODE_STATUSES)],
        ]);

        SmartQrCode::whereIn('id', $data['code_ids'])->update(['status' => $data['status']]);

        // ═══ ⚠️ `printed` IS AN EVENT, NOT ONLY A STATE ════════════════════════
        //
        // Setting status='printed' without a timestamp produced a code that said
        // "Printed" on its badge and "Not printed" in the same panel, because the
        // display reads printed_at. Three such rows existed in development before
        // this line — all from this action, which was the only way to reach that
        // combination through the UI.
        //
        // markPrinted() has always written both and is deliberately untouched.
        //
        // ⚠️ whereNull() IS THE WHOLE GUARD, and it is doing two jobs:
        //
        //   - it never OVERWRITES an existing timestamp, so re-selecting Printed
        //     on an already-printed code cannot rewrite when the print happened;
        //   - it makes the action idempotent, so a double-submit is harmless.
        //
        // ⚠️ AND NOTHING CLEARS printed_at WHEN MOVING AWAY FROM `printed`. A
        // code printed and later marked damaged keeps its timestamp, because that
        // is a historical fact and SmartQrDeletability::everPrinted() reads it to
        // refuse deletion. Clearing it would report a genuinely printed sticker as
        // never-printed and let destroy() take it — turning a cosmetic bug into a
        // destructive one.
        if ($data['status'] === SmartQrStatus::CODE_PRINTED) {
            SmartQrCode::whereIn('id', $data['code_ids'])
                ->whereNull('printed_at')
                ->update(['printed_at' => now()]);
        }

        return back()->with('success', __('Status updated.'));
    }

    /**
     * ⚠️ DELETE selected codes — refused for any that were printed or assigned.
     *
     * All-or-nothing, matching R-11's shape on the assignment path: a partial
     * delete would report failure while some rows were already gone, and there
     * is no undo for a deleted row.
     */
    public function destroy(Request $request, SmartQrDeletability $rule): RedirectResponse
    {
        $data = $request->validate([
            'code_ids' => ['required', 'array', 'min:1'],
            'code_ids.*' => ['integer', 'exists:smart_qr_codes,id'],
        ]);

        $codes = SmartQrCode::whereIn('id', $data['code_ids'])->get();

        $blocked = $codes
            ->map(fn (SmartQrCode $c) => [$c->serial_number, $rule->blockingReason($c)])
            ->filter(fn ($pair) => $pair[1] !== null);

        if ($blocked->isNotEmpty()) {
            $shown = $blocked->take(5)->map(fn ($p) => "{$p[0]} ({$p[1]})")->implode(', ');
            $more = $blocked->count() > 5 ? ' and '.($blocked->count() - 5).' more' : '';

            return back()->withErrors(['code_ids' => __(
                ':count of the selected codes have been printed or assigned (:shown:more) and '
                .'cannot be deleted. Retire them instead — deleting destroys assignment history '
                .'and leaves printed stickers unexplainable.',
                ['count' => $blocked->count(), 'shown' => $shown, 'more' => $more]
            )]);
        }

        app(AuditLogService::class)->logAdmin(
            'smart_qr.codes_deleted',
            SmartQrCode::class,
            null,
            ['count' => $codes->count(), 'serials' => $codes->pluck('serial_number')->all()],
            $request->user('admin'),
        );

        SmartQrCode::whereIn('id', $data['code_ids'])->delete();

        return back()->with('success', __(':count code(s) deleted.', ['count' => $codes->count()]));
    }

    /**
     * §5's "export" bulk action — the third hole slice 8 fills.
     *
     * ⚠️ QUEUED. 500 renders cannot happen in a web request, and an admin
     * watching a spinner time out reads it as a failure while the work carries
     * on invisibly.
     *
     * ⚠️ THE CAP IS ENFORCED HERE, WITH A MESSAGE. §14's "ZIP of 500" is an
     * example; making it a limit means an admin who ticks 5,000 is told so
     * immediately rather than discovering the ceiling by timeout.
     *
     * ⚠️ SVG is the default format, but NOT for the reason this docblock used to
     * give. It said "a few hundred KB against 50–150 MB of PNG". Measured, that
     * is backwards whenever a logo is configured: SVG is ~3× LARGER, because
     * endroid embeds the logo as base64 in every file. See
     * SmartQrImageRenderer::ZIPPED_BYTES_PER_CODE for the four measured figures.
     *
     * SVG stays the default on the argument that survives measurement — it is
     * vector and prints crisply at any size. PNG stays an explicit choice, and
     * the UI states the REAL size of each where the choice is made, so an admin
     * sees what they are asking for before they wait for it.
     */
    public function export(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code_ids' => ['required', 'array', 'min:1'],
            'code_ids.*' => ['integer', 'exists:smart_qr_codes,id'],
            'format' => ['nullable', Rule::in(GenerateQrExportJob::FORMATS)],
        ]);

        if (count($data['code_ids']) > GenerateQrExportJob::MAX_CODES) {
            return back()->withErrors(['code_ids' => __(
                'Select at most :max codes per export. You selected :count. Exporting more than '
                .':max in one archive risks a job that fails after several minutes of work.',
                ['max' => GenerateQrExportJob::MAX_CODES, 'count' => count($data['code_ids'])]
            )]);
        }

        GenerateQrExportJob::dispatch(
            array_values($data['code_ids']),
            $data['format'] ?? 'svg',
            $request->user('admin')?->id,
        );

        return back()->with('success', __(
            'Preparing an export of :count code(s). It will appear under "Ready exports" on this '
            .'page once the queue worker has built it.',
            ['count' => count($data['code_ids'])]
        ));
    }

    /**
     * Finished archives, newest first.
     *
     * ⚠️ THIS IS THE HALF THAT WAS MISSING. The job wrote a perfectly good ZIP
     * to `storage/app/private/smartqr-exports/` and nothing in the application
     * could reach it — not a link, not a route, not a listing. An export feature
     * whose output cannot be retrieved is not a feature.
     *
     * @return array<int, array{name: string, size: int, built_at: string}>
     */
    private function readyExports(): array
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::EXPORT_DIR)) {
            return [];
        }

        return collect($disk->files(self::EXPORT_DIR))
            ->filter(fn (string $f) => str_ends_with($f, '.zip'))
            ->map(fn (string $f) => [
                'name' => basename($f),
                'size' => $disk->size($f),
                'built_at' => date('Y-m-d H:i', $disk->lastModified($f)),
            ])
            ->sortByDesc('built_at')
            ->take(20)
            ->values()
            ->all();
    }

    public function exports(): JsonResponse
    {
        return response()->json(['exports' => $this->readyExports()]);
    }

    /**
     * ⚠️ `$name` is BASENAME-ONLY and re-validated against the listing.
     *
     * A route parameter interpolated into a storage path is a directory
     * traversal waiting to happen — `..%2f..%2f.env` is the classic. Matching
     * the request against the files we already decided to expose means a path
     * that is not in that list cannot be fetched, whatever it contains.
     */
    public function downloadExport(string $name): StreamedResponse
    {
        $safe = basename($name);

        $known = collect($this->readyExports())->firstWhere('name', $safe);

        if ($known === null) {
            abort(404);
        }

        return Storage::disk('local')->download(self::EXPORT_DIR.'/'.$safe);
    }
}
