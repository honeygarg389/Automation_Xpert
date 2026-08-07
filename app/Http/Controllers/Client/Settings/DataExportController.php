<?php

namespace App\Http\Controllers\Client\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateWorkspaceExportJob;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DataExportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('client/Settings/DataExport', [
            'status' => session('export_status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Capture the workspace HERE, while it is still known. The job has no
        // authenticated user, so it cannot resolve one later — see BUG-008.
        GenerateWorkspaceExportJob::dispatch(
            $request->user()->id,
            WorkspaceContext::id() ?? $request->user()->workspace_id,
        )->onQueue('default');

        return back()->with('export_status', 'Your export is being generated. You will receive an email with the download link shortly.');
    }
}
