<?php

namespace App\Modules\Flows\Services;

use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Persists a workspace-authored static Flow to Meta's create -> asset upload ->
 * publish API. This service deliberately owns no HTTP implementation; all
 * authenticated Graph calls remain in CloudApiClient.
 */
class WhatsappFlowMetaSyncService
{
    public const MISSING_MANAGEMENT_PERMISSION_MESSAGE = 'Your WhatsApp connection needs additional permissions to manage Flows. Please reconnect your WhatsApp Business Account.';

    public function __construct(
        private readonly WhatsappFlowJsonCompiler $compiler,
    ) {}

    /**
     * @return array{success:bool,message:string,validation_errors:list<array<string,mixed>>}
     */
    public function syncToMeta(WhatsappFlow $flow): array
    {
        $flow->update([
            'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_SYNCING,
            'meta_sync_error' => null,
            'meta_validation_errors' => null,
        ]);

        $waba = WhatsappBusinessAccount::query()
            ->where('workspace_id', $flow->workspace_id)
            ->where('status', 'active')
            ->first();
        $client = CloudApiClient::forWorkspace($flow->workspace_id);

        if (! $waba || ! $client) {
            return $this->fail($flow, 'Connect an active WhatsApp Business Account and phone number before syncing this Flow.');
        }

        try {
            if (! $flow->meta_flow_id) {
                $create = $client->createFlow(
                    $waba->waba_id,
                    $this->metaNameFor($flow),
                    $flow->category ?: 'OTHER',
                );

                if (! $create->successful()) {
                    return $this->failFromResponse($flow, $create);
                }

                $metaFlowId = (string) $create->json('id', '');
                if ($metaFlowId === '') {
                    return $this->fail($flow, 'Meta did not return a Flow ID. Please try syncing again.');
                }

                $flow->update(['meta_flow_id' => $metaFlowId]);
            }

            $upload = $client->uploadFlowJson((string) $flow->meta_flow_id, $this->compiler->compile($flow));
            if (! $upload->successful()) {
                return $this->failFromResponse($flow, $upload);
            }

            $validationErrors = $this->validationErrors($upload);
            if ($validationErrors !== []) {
                $flow->update([
                    'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_FAILED,
                    'meta_validation_errors' => $validationErrors,
                    'meta_sync_error' => 'Meta found validation errors in this Flow JSON.',
                ]);

                return [
                    'success' => false,
                    'message' => 'Meta found validation errors in this Flow JSON.',
                    'validation_errors' => $validationErrors,
                ];
            }

            $flow->update([
                'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT,
                'meta_validation_errors' => null,
                'meta_sync_error' => null,
            ]);

            return ['success' => true, 'message' => 'Flow synced to Meta as a draft.', 'validation_errors' => []];
        } catch (Throwable $exception) {
            return $this->fail($flow, 'The Flow JSON could not be synced. Please review the Flow and try again.');
        }
    }

    /**
     * @return array{success:bool,message:string,validation_errors:list<array<string,mixed>>}
     */
    public function publishToMeta(WhatsappFlow $flow): array
    {
        if (! $flow->meta_flow_id) {
            return $this->fail($flow, 'Sync this Flow to Meta before publishing it.');
        }

        $client = CloudApiClient::forWorkspace($flow->workspace_id);
        if (! $client) {
            return $this->fail($flow, 'Connect an active WhatsApp Business Account and phone number before publishing this Flow.');
        }

        try {
            $publish = $client->publishFlow($flow->meta_flow_id);
            if (! $publish->successful() || ! $publish->json('success', false)) {
                return $this->failFromResponse($flow, $publish);
            }

            $flow->update([
                'status' => WhatsappFlow::STATUS_PUBLISHED,
                'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_PUBLISHED,
                'meta_sync_error' => null,
            ]);

            return ['success' => true, 'message' => 'Flow published on Meta.', 'validation_errors' => []];
        } catch (Throwable $exception) {
            return $this->fail($flow, 'The Flow could not be published. Please try again.');
        }
    }

    /**
     * Pull every locally Meta-linked Flow in a workspace. This is intentionally
     * a Meta-authoritative operation: the UI requires an explicit warning
     * before it calls this method because saved local screens are overwritten.
     *
     * @param  Collection<int, WhatsappFlow>  $flows
     * @return array{updated:int,unchanged:int,failed:int}
     */
    public function pullAllFromMeta(Collection $flows): array
    {
        $summary = ['updated' => 0, 'unchanged' => 0, 'failed' => 0];
        foreach ($flows as $flow) {
            $outcome = $this->pullFromMeta($flow);
            $summary[$outcome]++;
        }

        return $summary;
    }

    /** @return 'updated'|'unchanged'|'failed' */
    public function pullFromMeta(WhatsappFlow $flow): string
    {
        if (! $flow->meta_flow_id) {
            return 'unchanged';
        }

        $client = CloudApiClient::forWorkspace($flow->workspace_id);
        if (! $client) {
            return $this->pullFailure($flow, 'Connect an active WhatsApp Business Account before pulling Flow JSON.');
        }

        try {
            $assets = $client->listFlowAssets($flow->meta_flow_id);
            if (! $assets->successful()) {
                return $this->pullFailureFromResponse($flow, $assets);
            }

            $downloadUrl = null;
            foreach ($this->flowAssets($assets) as $asset) {
                if (($asset['asset_type'] ?? null) === 'FLOW_JSON' && is_string($asset['download_url'] ?? null)) {
                    $downloadUrl = $asset['download_url'];

                    break;
                }
            }
            if (! is_string($downloadUrl) || $downloadUrl === '') {
                return $this->pullFailure($flow, 'Meta did not provide a Flow JSON asset to pull.');
            }

            $download = $client->downloadFlowAsset($downloadUrl);
            if (! $download->successful()) {
                return $this->pullFailure($flow, 'Meta Flow JSON could not be downloaded.');
            }
            $metaJson = json_decode($download->body(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($metaJson)) {
                return $this->pullFailure($flow, 'Meta returned invalid Flow JSON.');
            }

            $decompiled = $this->compiler->decompile($metaJson);
            if ($flow->screens === $decompiled['screens'] && $flow->submit_settings === $decompiled['submit_settings']) {
                return 'unchanged';
            }

            $flow->update([
                'screens' => $decompiled['screens'],
                'submit_settings' => $decompiled['submit_settings'],
                'meta_sync_error' => null,
            ]);

            return 'updated';
        } catch (Throwable) {
            return $this->pullFailure($flow, 'Meta Flow JSON could not be read. Review the Flow JSON and try again.');
        }
    }

    /**
     * The Meta Flows that exist on this workspace's WABA but have NO matching
     * local record yet — the picker's candidate list. Unlike pullAllFromMeta()
     * (which only ever touches flows we already know about), this reaches
     * across the boundary in the other direction: Meta knows about flows we
     * have never seen.
     *
     * Lightweight fields only (id, name, status, categories,
     * validation_errors) — the same set the picker in Meta's own Flow
     * management surface shows before committing to a full import.
     *
     * @return list<array{meta_flow_id:string,name:string,status:string,categories:list<string>,validation_errors:list<array<string,mixed>>}>
     */
    public function listImportableFlows(int $workspaceId): array
    {
        $waba = WhatsappBusinessAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->first();
        $client = CloudApiClient::forWorkspace($workspaceId);

        if (! $waba || ! $client) {
            throw new RuntimeException('Connect an active WhatsApp Business Account before importing Flows.');
        }

        $response = $client->listFlows($waba->waba_id);

        if (! $response->successful()) {
            throw new RuntimeException(
                self::isPermissionError($response)
                    ? self::MISSING_MANAGEMENT_PERMISSION_MESSAGE
                    : 'Meta could not list Flows for this account. Please try again.'
            );
        }

        // WhatsappFlow::query() is BelongsToWorkspace-scoped, so this already
        // reads only the CURRENT workspace's linked Meta Flow ids — a Flow
        // imported into workspace A can never suppress the same Meta Flow
        // from appearing importable in workspace B's picker.
        $linkedMetaFlowIds = WhatsappFlow::query()
            ->whereNotNull('meta_flow_id')
            ->pluck('meta_flow_id')
            ->all();

        $metaFlows = $response->json('data', []);
        if (! is_array($metaFlows)) {
            return [];
        }

        $importable = [];
        foreach ($metaFlows as $metaFlow) {
            if (! is_array($metaFlow) || ! is_string($metaFlow['id'] ?? null)) {
                continue;
            }
            if (in_array($metaFlow['id'], $linkedMetaFlowIds, true)) {
                continue;
            }

            $categories = $metaFlow['categories'] ?? [];
            $validationErrors = $metaFlow['validation_errors'] ?? [];

            $importable[] = [
                'meta_flow_id' => $metaFlow['id'],
                'name' => is_string($metaFlow['name'] ?? null) ? $metaFlow['name'] : 'Untitled Flow',
                'status' => is_string($metaFlow['status'] ?? null) ? $metaFlow['status'] : 'DRAFT',
                'categories' => is_array($categories) ? array_values(array_filter($categories, 'is_string')) : [],
                'validation_errors' => is_array($validationErrors) ? array_values(array_filter($validationErrors, 'is_array')) : [],
            ];
        }

        return $importable;
    }

    /**
     * Creates a NEW local WhatsappFlow for a Meta Flow that has no local
     * record — the picker's commit action. Meta's own name/category/status
     * seed the new row, and the actual screen content is fetched the same way
     * pullFromMeta() already does for a Flow it knows about: create the row
     * first (workspace-scoped, meta_flow_id set, a valid placeholder screens
     * value so the NOT NULL column is never violated), then hand it to
     * pullFromMeta() to populate the real content. If that content fetch
     * fails, the row is kept rather than discarded — meta_sync_error is
     * already recorded on it by pullFromMeta(), and the next "Sync Status"
     * bulk action (this workspace's existing retry path) will pick it back up
     * automatically, exactly as it would for any other linked Flow whose
     * content fetch failed.
     */
    public function importFlow(int $workspaceId, string $metaFlowId): WhatsappFlow
    {
        $client = CloudApiClient::forWorkspace($workspaceId);
        if (! $client) {
            throw new RuntimeException('Connect an active WhatsApp Business Account before importing a Flow.');
        }

        $meta = $client->getFlow($metaFlowId);
        if (! $meta->successful()) {
            throw new RuntimeException(
                self::isPermissionError($meta)
                    ? self::MISSING_MANAGEMENT_PERMISSION_MESSAGE
                    : 'Meta could not provide details for this Flow.'
            );
        }

        $metaStatus = is_string($meta->json('status')) ? $meta->json('status') : 'DRAFT';
        $categories = $meta->json('categories', []);
        $isPublished = $metaStatus === 'PUBLISHED';

        $flow = WhatsappFlow::create([
            'workspace_id' => $workspaceId,
            'name' => is_string($meta->json('name')) && $meta->json('name') !== '' ? $meta->json('name') : 'Imported Flow',
            'category' => is_array($categories) && is_string($categories[0] ?? null) ? $categories[0] : 'OTHER',
            'status' => $isPublished ? WhatsappFlow::STATUS_PUBLISHED : WhatsappFlow::STATUS_DRAFT,
            'screens' => $this->placeholderScreens(),
            'meta_flow_id' => $metaFlowId,
            'meta_sync_status' => $isPublished ? WhatsappFlow::META_SYNC_STATUS_PUBLISHED : WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT,
        ]);

        $this->pullFromMeta($flow);

        return $flow->fresh();
    }

    /**
     * Meta Flow names are constrained to a portable ASCII identifier and 64
     * characters. The workspace id plus the full UUID make collisions across
     * workspaces and repeat authoring effectively impossible, even if WABAs
     * are ever shared or Meta changes its name-uniqueness scope.
     */
    public function metaNameFor(WhatsappFlow $flow): string
    {
        $uuid = str_replace('-', '', (string) $flow->uuid);
        $suffix = '_w'.$flow->workspace_id.'_f'.$uuid;
        $base = Str::of(Str::ascii($flow->name))
            ->replaceMatches('/[^A-Za-z0-9_]+/', '_')
            ->trim('_')
            ->lower()
            ->value();
        $base = $base !== '' ? $base : 'flow';

        return substr($base, 0, max(1, 64 - strlen($suffix))).$suffix;
    }

    /** Shared Graph OAuth permission detection for Flow-management services. */
    public static function isPermissionError(Response $response): bool
    {
        $error = $response->json('error', []);
        $code = is_array($error) ? (int) ($error['code'] ?? 0) : 0;
        $message = strtolower((string) (is_array($error) ? ($error['message'] ?? '') : ''));

        return in_array($code, [10, 200], true)
            && (str_contains($message, 'permission') || str_contains($message, 'whatsapp_business_'));
    }

    /** @return list<array<string,mixed>> */
    private function validationErrors(Response $response): array
    {
        $errors = $response->json('validation_errors', []);

        return is_array($errors)
            ? array_values(array_filter($errors, 'is_array'))
            : [];
    }

    /**
     * A valid, well-formed screens value for the moment between creating an
     * imported Flow's row and pullFromMeta() overwriting it with the real
     * content. The `screens` column is NOT NULL, and if the content fetch
     * that follows genuinely fails, this is what the row is left holding —
     * so it stays syncable (WhatsappFlowJsonCompiler requires at least one
     * input field) rather than an empty, broken definition.
     *
     * @return list<array{id:string,title:string,fields:list<array<string,mixed>>}>
     */
    private function placeholderScreens(): array
    {
        return [[
            'id' => 'step_1',
            'title' => 'Imported from Meta',
            'fields' => [[
                'id' => 'field_1', 'type' => 'text', 'label' => 'Field', 'name' => 'field_1',
                'required' => false, 'helper_text' => null, 'options' => [], 'step' => 1, 'order' => 1,
            ]],
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flowAssets(Response $response): array
    {
        $assets = $response->json('data', []);
        if (! is_array($assets)) {
            return [];
        }

        return array_values(array_filter($assets, fn (mixed $asset): bool => is_array($asset)));
    }

    /** @return array{success:bool,message:string,validation_errors:list<array<string,mixed>>} */
    private function failFromResponse(WhatsappFlow $flow, Response $response): array
    {
        // Graph API OAuthException code 10: "Application does not have
        // permission for this action". Code 200 is Meta's generic
        // "Permissions error" variant; both are handled without exposing a
        // raw Graph error body to the client.
        if (self::isPermissionError($response)) {
            return $this->fail($flow, self::MISSING_MANAGEMENT_PERMISSION_MESSAGE);
        }

        return $this->fail($flow, 'Meta could not sync this Flow. Please try again or review your WhatsApp connection.');
    }

    /** @return array{success:bool,message:string,validation_errors:list<array<string,mixed>>} */
    private function fail(WhatsappFlow $flow, string $message): array
    {
        $flow->update([
            'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_FAILED,
            'meta_sync_error' => $message,
        ]);

        return ['success' => false, 'message' => $message, 'validation_errors' => []];
    }

    /** @return 'failed' */
    private function pullFailure(WhatsappFlow $flow, string $message): string
    {
        $flow->update(['meta_sync_error' => $message]);

        return 'failed';
    }

    /** @return 'failed' */
    private function pullFailureFromResponse(WhatsappFlow $flow, Response $response): string
    {
        if (self::isPermissionError($response)) {
            return $this->pullFailure($flow, self::MISSING_MANAGEMENT_PERMISSION_MESSAGE);
        }

        return $this->pullFailure($flow, 'Meta could not provide this Flow JSON. Please review your WhatsApp connection.');
    }
}
