<?php

namespace App\Modules\Flows\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Flows\Services\WhatsappFlowJsonCompiler;
use App\Modules\Flows\Services\WhatsappFlowMetaSyncService;
use App\Support\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Client authoring surface for static Flow definitions. No Meta API is called here. */
class WhatsappFlowController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('client/Flows/Index', [
            'flows' => WhatsappFlow::query()
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
            'flow' => [
                ...$this->summary($flow),
                'description' => $flow->description,
                'screens' => $flow->screens,
                'submit_settings' => $flow->submit_settings,
            ],
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

    public function destroy(WhatsappFlow $flow): RedirectResponse
    {
        $flow->delete();

        return to_route('client.flows.index')->with('success', 'Flow deleted.');
    }

    public function preview(WhatsappFlow $flow, WhatsappFlowJsonCompiler $compiler): JsonResponse
    {
        return response()->json($compiler->compile($flow));
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

        $result = $sync->publishToMeta($flow);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
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

    /** @return array<string,mixed> */
    private function summary(WhatsappFlow $flow): array
    {
        return [
            'id' => $flow->id,
            'uuid' => $flow->uuid,
            'name' => $flow->name,
            'category' => $flow->category,
            'status' => $flow->status,
            'meta_flow_id' => $flow->meta_flow_id,
            'meta_sync_status' => $flow->meta_sync_status,
            'meta_validation_errors' => $flow->meta_validation_errors,
            'meta_sync_error' => $flow->meta_sync_error,
            'field_count' => collect($flow->screens ?? [])->sum(fn (array $screen) => count($screen['fields'] ?? [])),
            'updated_at' => $flow->updated_at?->toISOString(),
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
}
