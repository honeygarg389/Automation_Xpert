<?php

namespace App\Modules\SmartQr\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Modules\SmartQr\Services\SmartQrAccess;
use App\Modules\SmartQr\Services\SmartQrAssignmentValidator;
use App\Modules\SmartQr\Services\SmartQrMetrics;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * §12 — Smart QR Reports.
 *
 * ⚠️ NOT a second Overview. §12 says "do not duplicate the same content in both
 * Overview and Reports" and then lists KPI cards under BOTH — a contradiction in
 * six lines. The reading: Overview (slice 6) keeps the OPERATIONAL snapshot —
 * what is assigned right now, recent activity. Reports gets the same metrics
 * over TIME, with filters, comparisons and export. Same measures, genuinely
 * different content.
 *
 * ⚠️ Every figure comes from the AGGREGATES via SmartQrMetrics::report(), never
 * from raw scans — R-26's reasoning generalised: raw rows stop at the 90-day
 * retention boundary, so a date-ranged chart built on them would show a cliff
 * that looks like the product stopped working.
 */
class SmartQrReportController extends Controller
{
    public function __construct(
        private readonly SmartQrMetrics $metrics,
        private readonly SmartQrAccess $access,
        private readonly SmartQrAssignmentValidator $validator,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);
        $filters = $this->filters($request);

        return Inertia::render('client/SmartQr/Reports', [
            'report' => $this->metrics->report($workspaceId, $filters),
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($workspaceId),
        ]);
    }

    /**
     * ⚠️ R-26 — AGGREGATES ONLY, and the UI says so on the button.
     *
     * A raw-scan export silently stops at the retention boundary. A customer who
     * downloads "their history" and finds it truncated has no way to know why:
     * the file looks complete and is not. The aggregates go back indefinitely,
     * so they are the only export that can honestly be called a history.
     */
    public function export(Request $request): StreamedResponse
    {
        $workspaceId = $this->workspaceId($request);
        $report = $this->metrics->report($workspaceId, $this->filters($request));

        return response()->stream(function () use ($report) {
            $csv = Writer::createFromStream(fopen('php://output', 'w'));

            // ⚠️ R-19 in the HEADER ROW. This file outlives the page that made
            // it and will be opened in a spreadsheet with no tooltip to explain
            // the column — so the word "Attributed" has to be in the name.
            $csv->insertOne([
                'Serial', 'Name', 'QR Type', 'Status',
                'Scans', 'Unique Scans', 'Attributed Messages',
            ]);

            foreach ($report['perQr'] as $row) {
                $csv->insertOne([
                    $row['serial_number'],
                    $row['name'],
                    $row['qr_type'],
                    $row['status'],
                    $row['scans'],
                    $row['unique_scans'],
                    $row['attributed_messages'],
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="smart-qr-report.csv"',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * §12's seven filters.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return array_filter($request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'assignment_id' => ['nullable', 'integer'],
            'serial' => ['nullable', 'string', 'max:64'],
            'qr_type' => ['nullable', 'string', 'max:32'],
            'assigned_user_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:32'],
            'channel_account_id' => ['nullable', 'integer'],
        ]), fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Options for the filter controls.
     *
     * ⚠️ Built from the same bounded sources the rest of the module uses —
     * SmartQrAccess for assignments, SmartQrAssignmentValidator for channels and
     * users — so a filter can never offer a value from another tenant.
     *
     * @return array<string, mixed>
     */
    private function filterOptions(int $workspaceId): array
    {
        $workspace = Workspace::findOrFail($workspaceId);

        $assignments = $this->access->allAssignmentsFor($workspaceId)
            ->with('code:id,serial_number')
            ->get();

        return [
            'qrCodes' => $assignments->map(fn ($a) => [
                'id' => $a->id,
                'label' => trim(($a->code->serial_number ?? '').' · '.($a->name ?? '')),
            ])->values(),
            'qrTypes' => $assignments->pluck('qr_type')->filter()->unique()->values(),
            'statuses' => SmartQrStatus::ASSIGNMENT_STATUSES,
            'channels' => $this->validator->channelsFor($workspace)
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->display_name])->values(),
            'users' => $this->validator->assignableUsersFor($workspace)
                ->map(fn ($u) => ['id' => $u->id, 'label' => $u->name])->values(),
        ];
    }

    private function workspaceId(Request $request): int
    {
        return (int) (WorkspaceContext::id() ?? $request->user()->workspace_id);
    }
}
