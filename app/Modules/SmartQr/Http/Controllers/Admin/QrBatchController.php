<?php

namespace App\Modules\SmartQr\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Scopes\WorkspaceScope;
use App\Modules\SmartQr\Http\Requests\AddQrBatchCodesRequest;
use App\Modules\SmartQr\Http\Requests\StoreQrBatchRequest;
use App\Modules\SmartQr\Http\Requests\UpdateQrBatchRequest;
use App\Modules\SmartQr\Jobs\GenerateQrBatchJob;
use App\Modules\SmartQr\Jobs\GenerateQrExportJob;
use App\Modules\SmartQr\Models\SmartQrAssignment;
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
use Illuminate\Support\Facades\DB;
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

            // ⚠️ The page must not decide this for itself. `import.meta.env.DEV`
            // reflects how the ASSETS were built, not what the server is — a
            // production build served from a local box, or a dev build proxied
            // elsewhere, would each get it wrong. The server is the only thing
            // that knows its own environment, and it is also the only thing
            // enforcing it (route + abort_unless); this prop merely keeps the UI
            // honest about a control that would 404 anyway.
            'forceDeleteAvailable' => app()->environment('local'),
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

        app(AuditLogService::class)->logAdmin(
            'smart_qr.batch_created',
            SmartQrBatch::class,
            $batch->id,
            ['batch_name' => $batch->batch_name, 'batch_number' => $batch->batch_number, 'quantity' => $batch->quantity],
            $request->user('admin'),
        );

        // Queued (§4): the spec's batches are 500 codes and the action chunks.
        GenerateQrBatchJob::dispatch($batch->id);

        return redirect()
            ->route('admin.qr.batches.show', $batch)
            ->with('success', __('Batch created. Code generation has been queued.'));
    }

    /**
     * "more QR's" — extend an existing batch's range. §4.
     *
     * ═══ ⚠️ NO NEW GENERATION PATH. THE ACTION ALREADY DOES THIS ════════════
     *
     * GenerateQrBatchAction resumes from the batch's highest existing serial and
     * fills out to `quantity`, so extending is: raise quantity, dispatch the same
     * job. A second "generate from X to Y" code path would be a second place for
     * the serial formula to live, and the two would drift.
     *
     * ⚠️ THE STATUS GATE IS NOT COSMETIC — it is what stops two generators
     * racing. `draft` and `generating` both mean a job is already in flight for
     * this batch; raising `quantity` underneath it makes the running job pick up
     * the extension mid-loop, and a concurrently dispatched second job then
     * resumes from a high-water mark its sibling is still moving. Both write
     * `generated_count`.
     *
     * ⚠️ THE GATE ONLY WORKS BECAUSE THIS ACTION MOVES THE STATUS ITSELF. It
     * reads a column the job would otherwise not write until the worker starts,
     * so a gate without the synchronous write below is a gate that lets the
     * second click straight through. See the comment on that write.
     *
     * `printed` is refused for a different reason: it has no writer anywhere in
     * the application today, so allowing it would ship an untestable branch. If
     * it ever becomes reachable the decision is a product one — whether a print
     * run can grow after it has been printed — not something to settle by
     * leaving the door open now.
     */
    public function addCodes(AddQrBatchCodesRequest $request, SmartQrBatch $batch): RedirectResponse
    {
        if (! in_array($batch->status, ['generated', 'failed'], true)) {
            return back()->withErrors(['additional_quantity' => __(
                'Codes can only be added to a batch that has finished generating. '
                .'This batch is :status.',
                ['status' => $batch->status]
            )]);
        }

        $additional = (int) $request->validated('additional_quantity');

        // ⚠️ increment(), not `quantity + $n` read-modify-write. The read in a
        // PHP-side sum is a snapshot; two admins extending the same batch in the
        // same second would each add to the same stale total and one extension
        // would vanish. increment() is a single atomic UPDATE.
        $batch->increment('quantity', $additional);

        // ⚠️ THE STATUS IS MOVED HERE, SYNCHRONOUSLY, AND NOT LEFT TO THE JOB.
        //
        // GenerateQrBatchAction::execute() also writes `generating`, but it does
        // so on the WORKER — so between this response and the worker picking the
        // job up, the batch still reads `generated`. Two things break in that
        // window, and both were measured before this line was added:
        //
        //   1. The batch page's auto-refresh never starts. Its guard is
        //      ['draft','generating'].includes(status), the redirect below
        //      re-renders show() immediately, and the props still say
        //      `generated` — so the admin watches a static page while codes
        //      appear in the database behind it.
        //
        //   2. The status gate above does not actually gate. A second submit
        //      arriving before the worker starts sees `generated` again and
        //      passes: measured at quantity 20 and TWO GenerateQrBatchJobs from
        //      two clicks. The docblock above claimed this gate prevented
        //      exactly that race; it did not until this write existed.
        //
        // store() has the same shape for the same reason — it persists `draft`
        // before dispatching rather than letting the job own the first status.
        $batch->forceFill(['status' => 'generating'])->save();

        GenerateQrBatchJob::dispatch($batch->id);

        return back()->with('success', __('Preparing :count more code(s). They will appear as generation completes.', [
            'count' => $additional,
        ]));
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
     * ═══ ⚠️ FORCE DELETE — LOCAL ONLY, AND IRREVERSIBLE ══════════════════════
     *
     * destroy() refuses a batch with any printed or ever-assigned code, because
     * removing one destroys a tenant's assignment history and leaves printed
     * stickers unexplainable. That refusal is correct on real inventory and
     * merely obstructive on a developer's test data, which is the only thing
     * this method exists for.
     *
     * ⚠️ THE ENVIRONMENT CHECK IS REPEATED HERE, and it is not redundant. The
     * route is registered inside `if (app()->environment('local'))`, so off
     * local there is nothing to call — but that guard protects the ROUTE, and
     * this one protects the METHOD. A future edit that moves the route into the
     * shared group, adds a second route, or reaches this method from a console
     * command would silently lose the only check if it lived in one place.
     *
     * ⚠️ 404, NOT 403. A 403 confirms the endpoint exists and is merely
     * forbidden; a 404 is indistinguishable from any other missing URL. Same
     * posture QrRedirectOutcome::INVALID already takes on the public scan page.
     */
    public function forceDestroy(Request $request, SmartQrBatch $batch): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        /**
         * ⚠️ CAPTURED INSIDE THE TRANSACTION, USED AFTER IT COMMITS.
         *
         * `smart_qr_exports.batch_id` is cascadeOnDelete, so the rows naming
         * these files are gone the moment the batch is — reading the paths
         * afterwards would return nothing and orphan every archive on disk.
         *
         * @var list<string> $exportPaths
         */
        $exportPaths = [];
        $counts = [];

        DB::transaction(function () use ($batch, $request, &$exportPaths, &$counts) {
            // Re-read under a row lock: two admins force-deleting the same batch
            // would otherwise both read the same counts and both audit a full
            // destruction, when only one of them performed it.
            $locked = SmartQrBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

            $codeIds = $locked->codes()->pluck('id');

            // ⚠️ Counted BEFORE anything is deleted, and counted through the
            // codes rather than trusting a cascade to report itself. Assignments
            // are workspace-scoped and an admin has no workspace, so the scope
            // would fail closed and report ZERO destroyed history — an audit
            // entry that understates what it destroyed is worse than none.
            $assignments = SmartQrAssignment::withoutWorkspaceScope(
                'reason: force-delete audits how much tenant history it is about to destroy, across '
                .'every tenant; the scope fails closed with no admin workspace context and would '
                .'record zero assignments destroyed'
            )->whereIn('smart_qr_code_id', $codeIds);

            $counts = [
                'codes_deleted' => $codeIds->count(),
                'assignments_deleted' => (clone $assignments)->count(),
                'assignments_current' => (clone $assignments)->whereNull('unassigned_at')->count(),
                'assignments_ended' => (clone $assignments)->whereNotNull('unassigned_at')->count(),
            ];

            $exportPaths = SmartQrExport::query()
                ->where('batch_id', $locked->id)
                ->pluck('path')
                ->filter(fn ($p) => is_string($p) && $p !== '')
                ->values()
                ->all();

            // ⚠️ AUDITED BEFORE THE DELETE, INSIDE THE TRANSACTION. Before,
            // because afterwards there is no row left to describe. Inside,
            // because if anything below fails the entry must roll back with it —
            // an audit record of a destruction that did not happen is a lie the
            // log cannot later correct.
            app(AuditLogService::class)->logAdmin(
                'smart_qr.batch_force_deleted',
                SmartQrBatch::class,
                $locked->id,
                array_merge(['batch_number' => $locked->batch_number], $counts, [
                    'exports_deleted' => count($exportPaths),
                ]),
                $request->user('admin'),
            );

            // ⚠️ CODES FIRST — `smart_qr_codes.batch_id` is restrictOnDelete, so
            // the batch cannot go while they exist. Deleting them cascades
            // assignments, and assignments cascade scan events, daily stats,
            // attribution sessions and conversion events. That whole subtree is
            // the "no recovery" this action warns about.
            //
            // ⚠️ Mass delete is safe HERE specifically: SmartQrCode declares no
            // booted() and no model events, so a per-row loop would fire nothing
            // extra and only cost N queries. Checked, not assumed — if a
            // deleting hook is ever added to that model, this line must become a
            // loop or the hook will be skipped silently.
            $locked->codes()->delete();

            // ⚠️ Model delete(), NOT the query builder's. SmartQrBatch::booted()
            // registers a `deleting` hook that removes the batch logo from disk;
            // a ->getQuery()->delete() would drop the row and orphan the file.
            $locked->delete();
        });

        $this->cleanUpExportFiles($exportPaths, $batch->id, $request);

        return redirect()
            ->route('admin.qr.batches.index')
            ->with('success', __(
                'Batch permanently purged: :codes code(s), :assignments assignment period(s) and '
                .':exports export file(s) destroyed. This cannot be undone.',
                [
                    'codes' => $counts['codes_deleted'] ?? 0,
                    'assignments' => $counts['assignments_deleted'] ?? 0,
                    'exports' => count($exportPaths),
                ]
            ));
    }

    /**
     * Remove the archives whose rows the cascade has already taken.
     *
     * ⚠️ RUNS AFTER THE TRANSACTION COMMITS, AND NEVER FAILS THE REQUEST.
     *
     * By this point the database state is gone and cannot be brought back by
     * throwing. A filesystem fault here means one orphaned archive in
     * storage/app/private — recoverable by hand, and far less harmful than
     * reporting failure for an operation that demonstrably succeeded. So each
     * file is deleted independently and a failure is recorded and stepped over.
     *
     * ⚠️ Audited rather than Log::error()'d, which DIVERGES from the sibling
     * `smart_qr.batch_logo_cleanup_failed` in SmartQrBatch::booted(). That hook
     * runs inside model events on any deletion path, including console
     * contexts with no admin to attribute; this runs in a request that has just
     * written `smart_qr.batch_force_deleted` to `audit_logs`, and a cleanup
     * failure belongs beside the destruction it belongs to.
     *
     * @param  list<string>  $paths
     */
    private function cleanUpExportFiles(array $paths, int $batchId, Request $request): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk('local')->delete($path);
            } catch (\Throwable $e) {
                app(AuditLogService::class)->logAdmin(
                    'smart_qr.export_cleanup_failed',
                    SmartQrBatch::class,
                    $batchId,
                    ['path' => $path, 'error' => $e->getMessage()],
                    $request->user('admin'),
                );
            }
        }
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
     * ⚠️ THE ORIGINAL REASON IS NOW FIXED, AND THIS ROUTE STILL STANDS. It used
     * to be that inventory.export-download validated the filename against
     * readyExports(), which truncates — so parts of a 20-part export fell out of
     * the window and 404'd for files that existed. That route now checks the
     * disk instead, and the truncation is presentation only.
     *
     * What remains is a different distinction: this route resolves a
     * smart_qr_exports ROW, so it can refuse a part that is queued, failed or
     * expired and can scope it to its batch. A filename check cannot know any of
     * that. Reusing the inventory route would offer a download link for a row
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

        // ⚠️ LITERAL .zip, AND $format IS A NAME SEGMENT — NOT THE EXTENSION.
        //
        // This shipped as `...-of%d.%s` with $format as the extension, which
        // produced `AX-BK-001-part1-of1.svg` for a file whose bytes are
        // `PK\x03\x04`. The archive would not open: an SVG viewer handed ZIP
        // bytes reports "Start tag expected, '<' not found", which reads as a
        // corrupt export rather than a misnamed one.
        //
        // The confusion was a category error. `format` describes the IMAGES
        // INSIDE the archive — it is used correctly one line away in the job,
        // naming members `T-000001.svg` — but the container is ALWAYS a ZIP,
        // for every format. GenerateQrExportJob::FORMATS is svg|png|pdf and
        // never contains "zip", so no value of $format was ever a correct
        // extension here.
        //
        // Kept in the name rather than dropped: exporting one batch as SVG and
        // again as PNG otherwise yields two files with identical names, and the
        // second silently becomes "(1)" in the admin's downloads folder.
        return $disk->download(
            $export->path,
            sprintf('%s-part%d-of%d-%s.zip', $batch->batch_number, $export->part_number, $export->total_parts, $export->format)
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

            // Server-decided, for the reason given on index().
            'forceDeleteAvailable' => app()->environment('local'),
        ]);
    }
}
