<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
use App\Modules\Entitlements\Support\Entitlements;
use App\Support\Files\SafeUploadExtension;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaService
{
    public function __construct(private StorageManager $storageManager) {}

    public function store(
        UploadedFile $file,
        Model $owner,
        string $collection = 'default',
        ?string $disk = null
    ): Media {
        // SEC-004. The STORED extension comes from the file's contents; the
        // client's filename is kept only as a display label. This is the
        // tenant-reachable site — POST /media — so it is the one that mattered:
        // a GIF-magic polyglot named `payload.html` passed `mimes:…,gif,…` and
        // was stored as `<uuid>.html`, served as text/html from the app origin.
        $ext = SafeUploadExtension::for($file);
        $filename = $file->getClientOriginalName();

        // Resolve disk from StorageManager unless caller explicitly passes one
        $resolvedDisk = $disk ?? $this->storageManager->diskName();
        $rawPath = 'media/'.Str::uuid().'.'.$ext;
        $path = $this->storageManager->prefixedPath($rawPath);

        Storage::disk($resolvedDisk)->putFileAs(dirname($path), $file, basename($path));

        return Media::create([
            'mediable_type' => get_class($owner),
            'mediable_id' => $owner->getKey(),
            'disk' => $resolvedDisk,
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'collection' => $collection,
        ]);
    }

    /**
     * Get total storage used by owner in bytes.
     */
    public function usedBytes(Model $owner, ?string $collection = null): int
    {
        $query = Media::where('mediable_type', get_class($owner))
            ->where('mediable_id', $owner->getKey());

        if ($collection) {
            $query->where('collection', $collection);
        }

        return (int) $query->sum('size_bytes');
    }

    /**
     * Storage quota in bytes.
     *
     * ─── ⚠️ THE KEY IS `storage_gb` AND IT DOES NOT EXIST ───────────────────
     *
     * `PlanSeeder` writes `storage` (in MEGABYTES: 5120 / 51200 / 512000). This
     * asks for `storage_gb`. The key has never matched, so the `?? 1` fires for
     * every customer on the system and everyone has a 1 GB quota regardless of
     * plan — including the enterprise plan, which intends 500 GB. BUG-025.
     *
     * It is NOT fixed here, and must not be. Renaming either side changes every
     * customer's quota in both directions and unannounced: enterprise from 1 GB
     * to 500 GB, and anyone currently over their real allowance into breach the
     * same day. The units differ too, so a rename alone would be wrong —
     * `storage: 5120` read as `storage_gb` grants 5120 GB. That needs its own
     * data decision and its own announcement.
     *
     * The facade is therefore asked for `storage_gb` exactly as before. It
     * returns null exactly as before, and the quota stays 1 GB exactly as
     * before. The bug is preserved deliberately; making it visible is not the
     * same as fixing it.
     *
     * ─── ⚠️ AND THE PLAN SOURCE DIFFERS FROM THE OTHER TWO SITES ────────────
     *
     * This site reads `User::effectiveSubscription()`; `EnforceLimit` and
     * `ContactCapacity` read `Client::activePlan()`. Those disagree for every
     * self-serve customer — the fourth instance of the one-concept-two-places
     * trap in this codebase, and the same shape as BUG-023.
     *
     * Routing this through the facade adopts the resolver's source. Today that
     * changes NO observable, because `storage_gb` is absent from every plan so
     * both sources return null. The moment BUG-025 is fixed and the key becomes
     * real, they diverge. Recorded rather than silently absorbed.
     */
    public function quotaBytes(User $user): int
    {
        if (Entitlements::isEnabled()) {
            $gb = app(Entitlements::class)->limitForClient($user->client, 'storage_gb') ?? 1;

            return (int) ($gb * 1024 * 1024 * 1024);
        }

        $plan = $user->effectiveSubscription()?->plan;
        $gb = $plan?->limitValue('storage_gb') ?? 1;

        return (int) ($gb * 1024 * 1024 * 1024);
    }
}
