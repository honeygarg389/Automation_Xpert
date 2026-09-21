<?php

namespace App\Modules\Flows\Services;

use App\Modules\Flows\Models\WhatsappFlow;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
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

    public const LOSSY_IMPORT_MESSAGE = 'This Flow\'s imported content could not be fully represented and cannot be synced until support is added — contact support or recreate this Flow\'s content manually.';

    public function __construct(
        private readonly WhatsappFlowJsonCompiler $compiler,
    ) {}

    /**
     * ⚠️ THE PLACEHOLDER-OVERWRITE GUARD. A Flow whose Meta content decompile()
     * could not represent holds placeholder (or stale) screens locally, so
     * compiling and uploading them would REPLACE the real content on Meta —
     * silently, and for a Draft, irreversibly. Every entry point that uploads or
     * publishes local content asks this first, and refuses WITHOUT touching the
     * row (no "syncing", no "failed"): nothing was attempted, so nothing is
     * recorded. Enforced here, not only in the controller, so no caller — a
     * route, a job, a future action — can bypass it.
     *
     * @return array{success:false,message:string,validation_errors:list<array<string,mixed>>}|null
     */
    private function refuseIfLossyImport(WhatsappFlow $flow): ?array
    {
        if (! $flow->isLossyImport()) {
            return null;
        }

        return ['success' => false, 'message' => self::LOSSY_IMPORT_MESSAGE, 'validation_errors' => []];
    }

    /**
     * @return array{success:bool,message:string,validation_errors:list<array<string,mixed>>}
     */
    public function syncToMeta(WhatsappFlow $flow): array
    {
        if (($refused = $this->refuseIfLossyImport($flow)) !== null) {
            return $refused;
        }

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
     * The single-click "Publish to Meta" action: validates the Flow has
     * content (reusing the compiler's own structural checks rather than
     * re-implementing them), syncs it (create-if-needed + upload JSON via
     * syncToMeta()), stops here with Meta's own validation errors surfaced
     * if the upload comes back invalid — syncToMeta() already fails and
     * records that itself, so a non-success result here already means
     * "do not proceed to publish", no separate check needed — and only then
     * publishes via publishOnly() below.
     *
     * The existing "Sync Draft to Meta" action still calls syncToMeta()
     * directly and stops there, unchanged, for the "push content and test in
     * Draft before going live" workflow this deliberately does not fold in.
     *
     * @return array{success:bool,message:string,validation_errors:list<array<string,mixed>>}
     */
    public function publishToMeta(WhatsappFlow $flow): array
    {
        // Before the compile below: on a refusal it would record a "failed" status
        // on a Flow this action never actually attempted.
        if (($refused = $this->refuseIfLossyImport($flow)) !== null) {
            return $refused;
        }

        try {
            $this->compiler->compile($flow);
        } catch (InvalidArgumentException $exception) {
            return $this->fail($flow, $exception->getMessage());
        }

        $syncResult = $this->syncToMeta($flow);
        if (! $syncResult['success']) {
            return $syncResult;
        }

        return $this->publishOnly($flow->fresh());
    }

    /**
     * Publish-only: requires the Flow to already be synced (has a
     * meta_flow_id). This is the pre-existing "Publish" action, unchanged —
     * reused directly by the chained publishToMeta() above once its own sync
     * step succeeds, and still reachable on its own via the existing
     * POST /{flow}/publish route for a Flow already synced via "Sync Draft
     * to Meta".
     *
     * @return array{success:bool,message:string,validation_errors:list<array<string,mixed>>}
     */
    public function publishOnly(WhatsappFlow $flow): array
    {
        // Publishing makes whatever is on Meta permanent and immutable. For a Flow
        // this app cannot represent, the user cannot see or verify that content
        // here, so this is refused too even though it uploads nothing itself.
        if (($refused = $this->refuseIfLossyImport($flow)) !== null) {
            return $refused;
        }

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
     * Section H refinement — duplicating a PUBLISHED flow. Meta's documented
     * Create-Flow-with-clone pattern (`clone_flow_id`) gives the new local
     * copy a genuine Meta-side lineage back to the original, instead of a
     * purely local copy that only happens to share the same compiled JSON.
     * A Draft source has no meaningful Meta-side identity to clone from yet,
     * so callers only reach this when the source is currently PUBLISHED on
     * Meta — see WhatsappFlowController::duplicate().
     *
     * Best-effort: if there is no active WABA/phone number, or Meta rejects
     * the clone call, the local duplicate row this was called for is left
     * exactly as a Draft-source duplicate would be — unconnected to Meta —
     * rather than failing the whole "Duplicate" action outright. The caller
     * decides what to tell the user from the returned message.
     *
     * @return array{success:bool,message:string}
     */
    public function cloneOnMeta(WhatsappFlow $copy, string $sourceMetaFlowId): array
    {
        $waba = WhatsappBusinessAccount::query()
            ->where('workspace_id', $copy->workspace_id)
            ->where('status', 'active')
            ->first();
        $client = CloudApiClient::forWorkspace($copy->workspace_id);

        if (! $waba || ! $client) {
            return ['success' => false, 'message' => 'Connect an active WhatsApp Business Account before cloning this Flow on Meta.'];
        }

        try {
            $response = $client->createFlow($waba->waba_id, $this->metaNameFor($copy), $copy->category ?: 'OTHER', $sourceMetaFlowId);
            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => self::isPermissionError($response) ? self::MISSING_MANAGEMENT_PERMISSION_MESSAGE : 'Meta could not clone this Flow.',
                ];
            }

            $metaFlowId = (string) $response->json('id', '');
            if ($metaFlowId === '') {
                return ['success' => false, 'message' => 'Meta did not return a Flow ID for the clone.'];
            }

            $copy->update([
                'meta_flow_id' => $metaFlowId,
                'meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT,
            ]);

            return ['success' => true, 'message' => 'Flow duplicated and cloned on Meta as a new draft.'];
        } catch (Throwable) {
            return ['success' => false, 'message' => 'Meta could not clone this Flow. Please try again.'];
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

    /**
     * Section E / Task 3 — the dashboard's single "Sync from Meta" button.
     * Does the whole reconcile-with-Meta job in one call: refreshes every
     * already-linked Flow's content (pullAllFromMeta(), unchanged) AND
     * automatically imports every Meta Flow this workspace has no local
     * record of yet, reusing importFlow()'s exact decompile-based creation
     * logic — the same logic the manual picker (listImportableFlows() +
     * importFlow(), still independently reachable and unchanged) already
     * used, just no longer gated on a separate manual selection step for
     * THIS action specifically.
     *
     * A workspace with no active WABA (or one Meta genuinely can't be
     * reached for) simply imports nothing here — that is reported as zero
     * imports, not as an error for the whole action, since the refresh half
     * may still have done real, useful work.
     *
     * @return array{updated:int,imported:int,errors:int}
     */
    public function syncAllFromMeta(int $workspaceId): array
    {
        $pull = $this->pullAllFromMeta(WhatsappFlow::query()
            ->whereNotNull('meta_flow_id')
            ->get());

        $imported = 0;
        $importErrors = 0;
        try {
            $candidates = $this->listImportableFlows($workspaceId);
        } catch (RuntimeException) {
            $candidates = [];
        }
        foreach ($candidates as $candidate) {
            try {
                $this->importFlow($workspaceId, $candidate['meta_flow_id']);
                $imported++;
            } catch (RuntimeException) {
                $importErrors++;
            }
        }

        return [
            'updated' => $pull['updated'],
            'imported' => $imported,
            'errors' => $pull['failed'] + $importErrors,
        ];
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
            if ($flow->screens === $decompiled['screens']
                && $flow->submit_settings === $decompiled['submit_settings']
                && ($flow->meta_passthrough ?? []) === $decompiled['meta_passthrough']) {
                // Task 3 — this branch used to return with NO update() call
                // at all, so a STALE meta_sync_error left over from an
                // earlier failed pull attempt (e.g. a transient download
                // failure) could sit there indefinitely: the local content
                // never changed, so every later pull kept landing on
                // "unchanged" and never reached the 'updated' branch below,
                // which is the only place this used to get cleared. A
                // successful pull — even one that finds nothing to change —
                // is still positive proof the Flow is fine, and must clear
                // it, not just a pull that also changes content.
                // The same applies to import_unsupported_reason: a clean decompile
                // is the ONLY thing that lifts the placeholder-overwrite guard.
                if ($flow->meta_sync_error !== null || $flow->isLossyImport()) {
                    $flow->update(['meta_sync_error' => null, 'import_unsupported_reason' => null]);
                }

                return 'unchanged';
            }

            $flow->update([
                'screens' => $decompiled['screens'],
                'submit_settings' => $decompiled['submit_settings'],
                // Section D — Meta's data-exchange/routing metadata this
                // Flow may carry, so a later re-compile re-emits it instead
                // of silently dropping it. Null rather than [] when there is
                // nothing to preserve — matches the column's "nothing here"
                // default rather than persisting a meaningless empty object.
                'meta_passthrough' => $decompiled['meta_passthrough'] !== [] ? $decompiled['meta_passthrough'] : null,
                'meta_sync_error' => null,
                'import_unsupported_reason' => null,
            ]);

            return 'updated';
        } catch (UnsupportedMetaFlowShapeException $exception) {
            // Deterministic and about the Flow itself: retrying cannot fix it, so
            // it must not read as the "try again" read failure below. Local
            // screens are deliberately left untouched — they are whatever the
            // row already held — and the row is now guarded against uploads.
            return $this->pullUnsupported($flow, $exception->getMessage());
        } catch (Throwable) {
            // A genuinely unreadable payload or an unexpected failure. Structural
            // refusals no longer land here.
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
     * Section G — the "Delete" action, state-machine-aware. Meta's own error
     * 139004 ("Can't delete published Flow... deprecate instead") means a
     * single DELETE call is only correct for two of three states, and wrong
     * (a guaranteed API failure) for the third:
     *
     *   - Never synced (no meta_flow_id): nothing exists on Meta to remove —
     *     the caller does a plain local soft-delete, no Graph call at all.
     *   - Synced but still Draft on Meta: Meta's real DELETE /{flow-id} is
     *     valid here — call it, then the caller does the local soft-delete
     *     too, exactly matching "Delete" everywhere it is used.
     *   - Published on Meta: DELETE is REJECTED by Meta outright. The only
     *     valid action is deprecate — POST /{flow-id}/deprecate — which is
     *     irreversible and is NOT a delete: the row and its submission
     *     history stay, only meta_sync_status changes to 'deprecated'. The
     *     caller must NOT soft-delete in this branch.
     *
     * On any Meta-side failure, nothing local changes — no soft-delete, no
     * status flip — so the local row never claims an outcome ("deleted",
     * "deprecated") that Meta itself refused. A retry from the exact same
     * state is always safe.
     *
     * @return array{success:bool,message:string,action:'local_only'|'meta_delete'|'meta_deprecate'}
     */
    public function removeFromMeta(WhatsappFlow $flow): array
    {
        if (! $flow->meta_flow_id) {
            return ['success' => true, 'message' => 'Flow deleted.', 'action' => 'local_only'];
        }

        $client = CloudApiClient::forWorkspace($flow->workspace_id);
        $isPublished = $flow->meta_sync_status === WhatsappFlow::META_SYNC_STATUS_PUBLISHED;

        if (! $client) {
            return [
                'success' => false,
                'message' => 'Connect an active WhatsApp Business Account before '.($isPublished ? 'deprecating' : 'deleting').' this Flow.',
                'action' => $isPublished ? 'meta_deprecate' : 'meta_delete',
            ];
        }

        if ($isPublished) {
            $response = $client->deprecateFlow($flow->meta_flow_id);
            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => self::isPermissionError($response) ? self::MISSING_MANAGEMENT_PERMISSION_MESSAGE : 'Meta could not deprecate this Flow. Please try again.',
                    'action' => 'meta_deprecate',
                ];
            }

            $flow->update(['meta_sync_status' => WhatsappFlow::META_SYNC_STATUS_DEPRECATED]);

            return ['success' => true, 'message' => 'Flow deprecated on Meta. This cannot be undone.', 'action' => 'meta_deprecate'];
        }

        $response = $client->deleteFlow($flow->meta_flow_id);
        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => self::isPermissionError($response) ? self::MISSING_MANAGEMENT_PERMISSION_MESSAGE : 'Meta could not delete this Flow. Please try again.',
                'action' => 'meta_delete',
            ];
        }

        return ['success' => true, 'message' => 'Flow deleted.', 'action' => 'meta_delete'];
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
        // Section B (imported-flow round-trip fix) — this used to hardcode
        // the literal "field_1" as both id and name. If the content fetch
        // below then fails, this placeholder is what stays on the row
        // (see the docblock above) — and duplicating THAT flow before a
        // retry re-sync used to carry the numeric-suffixed "field_1" name
        // straight through recompile into Meta's upload. nextUnique() with a
        // fresh $used produces the bare "field" (no suffix at all, since
        // nothing else claims it here), which can never collide with this
        // shape again.
        $used = [];
        $name = MetaFlowIdentifier::nextUnique($used, 'field');

        return [[
            'id' => 'step_1',
            'title' => 'Imported from Meta',
            'fields' => [[
                'id' => $name, 'type' => 'text', 'label' => 'Field', 'name' => $name,
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

        // Task 2 — Graph error 139001, confirmed live against Meta's real
        // API: "Updating attempt failed" / "Flow can only be modified in
        // Draft status". Distinct from a permission problem or a malformed
        // request — the Flow's Meta-side copy has moved past Draft (usually
        // deprecated or published directly on Meta, outside this app's own
        // publish()/removeFromMeta() actions), which is exactly what made
        // the LOCAL meta_sync_status stale in the first place.
        if (self::isFlowNotDraftError($response)) {
            return $this->failFlowNotDraft($flow);
        }

        return $this->fail($flow, 'Meta could not sync this Flow. Please try again or review your WhatsApp connection.');
    }

    /** Graph error 139001 — a real error CODE, not a message-substring heuristic, so it is not vulnerable to Meta rewording the message text. */
    private static function isFlowNotDraftError(Response $response): bool
    {
        $error = $response->json('error', []);
        $code = is_array($error) ? (int) ($error['code'] ?? 0) : 0;

        return $code === 139001;
    }

    /**
     * Task 2 self-healing — one follow-up getFlow() call (the same method
     * the import picker already uses) reconciles LOCAL meta_sync_status to
     * Meta's REAL current status, so the dashboard badge (Section N's
     * single-source-of-truth logic, which is authoritative on
     * meta_sync_status once meta_flow_id exists) reflects reality going
     * forward instead of staying wrong until a manual "Sync from Meta".
     *
     * Deliberately does NOT also set meta_sync_error: once reconciled,
     * DEPRECATED/PUBLISHED are normal terminal states (see Section G), not
     * an ongoing error condition the badge should keep flagging red forever
     * — the specific explanation is returned as THIS result's message,
     * surfaced once as a flash banner, not persisted as a standing claim.
     *
     * @return array{success:bool,message:string,validation_errors:list<array<string,mixed>>}
     */
    private function failFlowNotDraft(WhatsappFlow $flow): array
    {
        $message = "This Flow's Meta-side copy is no longer in Draft status (it may have been deprecated or published directly on Meta) and can no longer be updated. Check its current status or create a new version.";

        $client = CloudApiClient::forWorkspace($flow->workspace_id);
        $metaStatus = $client?->getFlow((string) $flow->meta_flow_id)->json('status');
        $reconciled = match ($metaStatus) {
            'PUBLISHED' => WhatsappFlow::META_SYNC_STATUS_PUBLISHED,
            'DEPRECATED' => WhatsappFlow::META_SYNC_STATUS_DEPRECATED,
            'DRAFT' => WhatsappFlow::META_SYNC_STATUS_SYNCED_DRAFT,
            default => null,
        };

        // The follow-up call itself failing, or returning a status this
        // service doesn't model, is not swallowed silently — fall back to
        // the existing FAILED bookkeeping rather than leaving
        // meta_sync_status ambiguously untouched.
        $flow->update(['meta_sync_status' => $reconciled ?? WhatsappFlow::META_SYNC_STATUS_FAILED]);

        return ['success' => false, 'message' => $message, 'validation_errors' => []];
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

    /**
     * Records that Meta holds content this app cannot represent. The reason goes
     * to BOTH columns on purpose: meta_sync_error is what every existing surface
     * (the pull flash, the Info modal) already reads, so the honest text replaces
     * the old misleading one everywhere at once; import_unsupported_reason is the
     * durable classification the upload guard keys on, which a later network
     * error overwriting meta_sync_error cannot erase.
     *
     * @return 'failed'
     */
    private function pullUnsupported(WhatsappFlow $flow, string $reason): string
    {
        $flow->update(['meta_sync_error' => $reason, 'import_unsupported_reason' => $reason]);

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
