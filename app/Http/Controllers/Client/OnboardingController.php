<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\OnboardingService;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function __construct(private OnboardingService $onboarding) {}

    public function show(Request $request): Response
    {
        $progress = $this->onboarding->getProgress($request->user(), WorkspaceContext::id() ?? $request->user()->workspace_id);

        return Inertia::render('client/Onboarding/Wizard', [
            'progress' => $progress,
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'step' => ['required', 'string', 'in:'.implode(',', array_keys(OnboardingService::STEPS))],
        ]);

        $ok = $this->onboarding->markStep(
            $request->user(),
            WorkspaceContext::id() ?? $request->user()->workspace_id,
            $validated['step'],
            false,
        );

        return response()->json(['ok' => $ok]);
    }
}
