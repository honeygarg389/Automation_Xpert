<?php

namespace App\Modules\Flows\Services;

use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
