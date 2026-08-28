<?php

namespace App\Modules\SmartQr\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Scopes\WorkspaceScope;
use App\Modules\SmartQr\Http\Requests\StoreQrBatchRequest;
use App\Modules\SmartQr\Http\Requests\UpdateQrBatchRequest;
use App\Modules\SmartQr\Jobs\GenerateQrBatchJob;
use App\Modules\SmartQr\Jobs\GenerateQrExportJob;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrExport;
use App\Modules\SmartQr\Services\SmartQrDeletability;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Services\AuditLogService;
use App\Services\StorageManager;
use App\Support\Files\SafeUploadExtension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §4 — QR batches. Super Admin only; a batch never belongs to a tenant.
 */
class QrBatchController extends Controller
{
    public function index(): Response
    {
        $batches = SmartQrBatch::query()
            ->withCount([
                'codes',
                // ⚠️ R-12 — assigned_count is DERIVED, never stored.
                //
                // The spec's §4 field list names it as a batch column. A stored
                // counter must be incremented on assign and decremented on
                // unassign by every path that ever writes an assignment, forever
                // — and drift in a count nobody checks is invisible: the number
                // stays plausible and stops being true.
                //
                // `unassigned_at IS NULL` is the same current-period predicate
                // the gauge and the DB's unique index use.
                'codes as assigned_count' => fn ($q) => $q->whereHas(
                    'currentAssignment',
                    fn ($a) => $a->withoutGlobalScope(WorkspaceScope::class)
                ),
            ])
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/SmartQr/Batches/Index', [
            'batches' => $batches,
        ]);
    }

    public function store(StoreQrBatchRequest $request): RedirectResponse
    {
        // ⚠️ `logo` IS IN validated() AND IS NOT A COLUMN.
        //
        // FormRequest::validated() returns the uploaded file under its own key,
        // so passing it straight into create() would try to set an attribute
        // named `logo` on a table that has no such column. Stripped explicitly
        // rather than relied upon to be absent — it is present whenever a file
        // was actually uploaded, which is the only case that matters.
        $data = Arr::except($request->validated(), ['logo']);

        $batch = SmartQrBatch::create($data + $this->storeBatchLogo($request->file('logo')) + [
            'status' => 'draft',
            'created_by_admin_id' => $request->user('admin')?->id,
        ]);

        // Queued (§4): the spec's batches are 500 codes and the action chunks.
        GenerateQrBatchJob::dispatch($batch->id);

        return redirect()
            ->route('admin.qr.batches.show', $batch)
            ->with('success', __('Batch created. Code generation has been queued.'));
    }

    /**
     * ⚠️ RENAME ONLY. batch_name and batch_number, nothing else.
     *
     * `prefix`, `serial_start` and `quantity` are deliberately NOT editable:
     * they define the serial range, the codes are already generated from them,
     * and changing them would leave every existing serial describing a range the
     * batch no longer claims. That is a regeneration, not a rename.
     */
    public function update(UpdateQrBatchRequest $request, SmartQrBatch $batch): RedirectResponse
    {
        $before = $batch->only(['batch_name', 'batch_number']);
        $batch->update($request->validated());

        app(AuditLogService::class)->logAdmin(
            'smart_qr.batch_renamed',
            SmartQrBatch::class,
            $batch->id,
            ['before' => $before, 'after' => $batch->only(['batch_name', 'batch_number'])],
            $request->user('admin'),
        );

        return back()->with('success', __('Batch updated.'));
    }

    /**
     * ⚠️ DELETE — refused unless EVERY code in the batch is deletable.
     *
     * Deleting a batch deletes all of its codes, so one printed sticker among
     * five hundred makes the whole batch retire-only. The refusal NAMES the
     * codes that blocked it: "refused" alone leaves an operator hunting through
     * five hundred rows for the one that matters.
     */
    public function destroy(Request $request, SmartQrBatch $batch, SmartQrDeletability $rule): RedirectResponse
    {
        $blockers = $rule->blockersIn($batch);

        if ($blockers->isNotEmpty()) {
            $shown = $blockers->take(5)
                ->map(fn ($reason, $serial) => "{$serial} ({$reason})")
                ->implode(', ');
            $more = $blockers->count() > 5 ? ' and '.($blockers->count() - 5).' more' : '';

            return back()->withErrors(['batch' => __(
                'This batch cannot be deleted: :count of its codes have been printed or assigned '
                .'(:shown:more). Retire it instead — deleting would destroy assignment history '
                .'and leave printed stickers unexplainable.',
                ['count' => $blockers->count(), 'shown' => $shown, 'more' => $more]
            )]);
        }

        $codeCount = (int) $batch->codes()->count();
        $number = $batch->batch_number;

        // Audit BEFORE the delete: afterwards there is no row to describe.
        app(AuditLogService::class)->logAdmin(
            'smart_qr.batch_deleted',
            SmartQrBatch::class,
            $batch->id,
            ['batch_number' => $number, 'codes_deleted' => $codeCount],
            $request->user('admin'),
        );

        // ⚠️ Codes first. `smart_qr_codes.batch_id` is restrictOnDelete, so the
        // batch cannot be removed while they exist — by design, so a batch can
        // never be orphaned from its codes by accident.
        $batch->codes()->delete();
        $batch->delete();

        return redirect()
            ->route('admin.qr.batches.index')
            ->with('success', __(':count code(s) and their batch were deleted.', ['count' => $codeCount]));
    }

    /**
     * RETIRE — the path that always works.
     *
     * Keeps every row. The codes stay in inventory, cannot be assigned, and
     * (slice 4) resolve to "no longer active" rather than a 404.
     */
    public function retire(Request $request, SmartQrBatch $batch): RedirectResponse
    {
        $affected = $batch->codes()
            ->where('status', '!=', SmartQrStatus::CODE_RETIRED)
            ->update(['status' => SmartQrStatus::CODE_RETIRED]);

        app(AuditLogService::class)->logAdmin(
            'smart_qr.batch_retired',
            SmartQrBatch::class,
            $batch->id,
            ['batch_number' => $batch->batch_number, 'codes_retired' => $affected],
            $request->user('admin'),
        );

        return back()->with('success', __(':count code(s) retired.', ['count' => $affected]));
    }

    /**
     * ═══ ⚠️ BATCH-SCOPED EXPORT — N PARTS, ONE JOB EACH ═══════════════════
     *
     * ⚠️ THE CODE IDS COME FROM THE BATCH, NEVER FROM THE REQUEST. The
     * Inventory export accepts `code_ids[]` because that screen lets an admin
     * hand-pick an arbitrary cross-batch selection; here the batch's own rows
     * ARE the selection, so accepting a client-supplied list would add an input
     * that can only disagree with the server's own answer. `format` is the only
     * thing this reads from the request.
     *
     * ⚠️ N DISPATCHES, NOT ONE JOB LOOPING. Each execution gets at most
     * MAX_CODES ids — the exact shape GenerateQrExportJob already runs safely
     * today. A single job emitting N archives would put the whole batch inside
     * one execution's timeout, which is the risk the 500 cap exists to bound.
     *
     * ⚠️ THE ROW IS WRITTEN BEFORE THE JOB IS QUEUED. `total_parts` is known up
     * front because the ids are counted before anything is dispatched, so every
     * part can say "3 of 20" from birth — and no job can ever be running with
     * no row to record what happened to it.
     */
    public function export(Request $request, SmartQrBatch $batch): RedirectResponse
    {
        $data = $request->validate([
            'format' => ['nullable', Rule::in(GenerateQrExportJob::FORMATS)],
        ]);

        $format = $data['format'] ?? 'svg';

        $codeIds = $batch->codes()->orderBy('serial_number')->pluck('id')->all();

        if ($codeIds === []) {
            return back()->withErrors(['export' => __(
                'This batch has no codes to export yet. Code generation may still be queued.'
            )]);
        }

        $chunks = array_chunk($codeIds, GenerateQrExportJob::MAX_CODES);
        $totalParts = count($chunks);

        foreach ($chunks as $index => $chunk) {
            $export = SmartQrExport::create([
                'batch_id' => $batch->id,
                'part_number' => $index + 1,
                'total_parts' => $totalParts,
                'format' => $format,
                'status' => SmartQrExport::STATUS_QUEUED,
            ]);

            // ⚠️ No array_values() — array_chunk already returns a list, and
            // PHPStan flags the wrap as having no effect. (The Inventory export
            // does call it, correctly: there it re-indexes a client-supplied
            // array whose keys are not guaranteed sequential.)
            GenerateQrExportJob::dispatch(
                $chunk,
                $format,
                $request->user('admin')?->id,
                $export->id,
            );
        }

        return back()->with('success', __(
            'Preparing :parts export part(s) for :count code(s). Each part appears below as its '
            .'queue job finishes.',
            ['parts' => $totalParts, 'count' => count($codeIds)]
        ));
    }

    /**
     * The parts of this batch's export, with their statuses.
     *
     * ⚠️ Scoped to the batch and UNCAPPED — see SmartQrExport::scopeForBatch.
     * QrInventoryController's readyExports() takes the 20 most recent files
     * because it lists a shared directory; a 20-part batch would fill that
     * entirely. Keyed on batch_id there is nothing to cap.
     */
    public function exports(SmartQrBatch $batch): JsonResponse
    {
        return response()->json([
            'exports' => SmartQrExport::forBatch($batch->id)
                ->get(['id', 'part_number', 'total_parts', 'format', 'status', 'path', 'error', 'updated_at'])
                ->all(),
        ]);
    }

    /**
     * Download one ready export part.
     *
     * ═══ ⚠️ WHY THIS EXISTS RATHER THAN REUSING inventory.export-download ══
     *
     * That route validates the requested filename against readyExports(),
     * which lists the export directory and TAKES THE 20 MOST RECENT. Batch
     * parts are written to that same directory, so a 20-part batch export plus
     * any other recent archive pushes the earliest parts out of that window —
     * and out of the window means 404, for a file that exists and that the
     * admin was just told was ready. That is precisely the cap this feature was
     * built to escape, so reusing the route would reintroduce it at the last
     * step.
     *
     * Keyed on the smart_qr_exports ROW instead: the row is the authority on
     * what belongs to this batch and whether it is downloadable. No listing, no
     * cap, no filename parsing.
     *
     * ⚠️ THE PART IS SCOPED TO THE BATCH IN THE QUERY, not just read by id — so
     * a part id belonging to another batch 404s rather than being served under
     * this batch's URL.
     */
    public function downloadExport(SmartQrBatch $batch, SmartQrExport $export): StreamedResponse
    {
        abort_unless($export->batch_id === $batch->id, 404);

        // ⚠️ Only a READY row has an archive. queued/processing would 404 on
        // the disk read anyway; failed never wrote one. Refusing here makes the
        // reason explicit instead of surfacing a storage error.
        abort_unless($export->status === SmartQrExport::STATUS_READY && $export->path !== null, 404);

        $disk = Storage::disk('local');

        // The row can outlive its file — the archive is disposable, the record
        // is not. A missing file is a 404, not a 500.
        abort_unless($disk->exists($export->path), 404);

        return $disk->download(
            $export->path,
            sprintf('%s-part%d-of%d.%s', $batch->batch_number, $export->part_number, $export->total_parts, $export->format)
        );
    }

    /**
     * ═══ ⚠️ THE BATCH LOGO — WRITTEN TO THE PRIVATE DISK, ON PURPOSE ══════
     *
     * The platform logo lives on `public` because a browser fetches it. This
     * one is never fetched by a browser: it is read SERVER-SIDE by GD/endroid
     * during a render and composited into the output. Nothing links to it, so
     * `local` (private) is both sufficient and tighter — the same disk the
     * export ZIPs already use, and the same reason.
     *
     * ⚠️ THE DISK NAME IS STORED ALONGSIDE THE PATH. A path with no disk is a
     * guess, and a wrong guess resolves to "file absent" rather than to an
     * error — which is a plain sticker instead of a branded one, discovered in
     * print.
     *
     * ⚠️ SEC-004: the stored extension comes from SafeUploadExtension::for(),
     * which sniffs the CONTENT. getClientOriginalExtension() is an attacker
     * string. The name is a UUID, so an upload cannot choose where it lands or
     * overwrite anything.
     *
     * @return array{logo_path: string, logo_disk: string}|array{}
     */
    private function storeBatchLogo(?UploadedFile $file): array
    {
        if ($file === null) {
            return [];
        }

        $path = app(StorageManager::class)->prefixedPath(
            'branding/qr-logo-'.Str::uuid().'.'.SafeUploadExtension::for($file)
        );

        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        return ['logo_path' => $path, 'logo_disk' => 'local'];
    }

    public function show(SmartQrBatch $batch): Response
    {
        // ═══ ⚠️ assigned_count / active_count — DERIVED, MIRRORING R-12 ═════
        //
        // Not read from `codes` below: that prop is paginated at 50, and a
        // batch's `quantity` runs up to 10,000 — deriving from the visible
        // page would silently undercount every batch past its first page.
        // loadCount() runs one extra query against the already-route-bound
        // model rather than re-fetching it, the single-instance counterpart
        // to index()'s withCount() on the same 'codes as X' => whereHas(...)
        // shape (R-12: never a stored column, so nothing can drift).
        //
        // ⚠️ THE WorkspaceScope REMOVAL, KEPT FOR CONSISTENCY WITH R-12 —
        // MEASURED TO BE A NO-OP ON THIS ROUTE, AND THAT IS WORTH RECORDING
        // RATHER THAN LEAVING AS AN UNVERIFIED ASSUMPTION.
        //
        // The naive expectation (and what this comment originally claimed,
        // before it was checked with a mutation test) is hazard H-2:
        // whereHas()/withCount() build a fresh EXISTS subquery that does not
        // inherit whatever was chained onto the relation *method*, so removing
        // the closure-level unscoping should silently collapse both counts to
        // 0. Mutation-tested directly against this action, and it does NOT:
        // WorkspaceScope::apply() returns immediately when
        // `Auth::guard('admin')->check()` is true, BEFORE it ever attaches a
        // constraint — and `show()` sits under this module's `auth:admin`
        // route group with no other entry point, so that check is always true
        // whenever this code runs. The scope never filters here regardless of
        // whether the closure re-removes it.
        //
        // Kept anyway: it matches R-12's established closure shape verbatim
        // (index()'s own `assigned_count`, under the same admin route group,
        // has the identical property — not touched here, out of this
        // change's scope, but worth knowing before treating that comment as
        // proof this one is load-bearing), and it costs nothing to leave in
        // place against a future caller that is NOT admin-guard-only.
        $batch->loadCount([
            'codes as assigned_count' => fn ($q) => $q->whereHas(
                'currentAssignment',
                fn ($a) => $a->withoutGlobalScope(WorkspaceScope::class)
            ),
            'codes as active_count' => fn ($q) => $q->whereHas(
                'currentAssignment',
                fn ($a) => $a->withoutGlobalScope(WorkspaceScope::class)
                    ->where('status', SmartQrStatus::ASSIGNMENT_ACTIVE)
            ),
        ]);

        return Inertia::render('Admin/SmartQr/Batches/Show', [
            'batch' => $batch,
            'codes' => $batch->codes()
                ->with(['currentAssignment' => fn ($q) => $q->withoutGlobalScope(WorkspaceScope::class)])
                ->orderBy('serial_number')
                ->paginate(50),

            // ⚠️ AN INERTIA PROP, NOT A POLLED FETCH — matching this module's
            // established "fire and forget, check back" pattern. The Inventory
            // page's Ready Exports panel works identically (a prop at page
            // load; its JSON endpoint has no caller anywhere in resources/js),
            // and introducing a poller here would make this the only screen in
            // the module that behaves differently. The JSON exports() route
            // stays available for a future slice that wants live refresh.
            'exports' => SmartQrExport::forBatch($batch->id)
                ->get(['id', 'part_number', 'total_parts', 'format', 'status', 'path', 'error', 'updated_at']),
        ]);
    }
}
