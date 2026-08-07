<?php

namespace App\Services;

use App\Models\Media;
use App\Models\User;
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
     * Get storage quota in bytes from plan limits (storage_gb).
     */
    public function quotaBytes(User $user): int
    {
        $plan = $user->effectiveSubscription()?->plan;
        $gb = $plan?->limitValue('storage_gb') ?? 1;

        return (int) ($gb * 1024 * 1024 * 1024);
    }
}
