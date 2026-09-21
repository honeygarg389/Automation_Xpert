<?php

namespace App\Modules\Restaurant\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string|null $brand_name
 * @property string|null $legal_business_name
 * @property string|null $registered_business_address
 * @property string|null $logo_path
 * @property string|null $logo_disk
 * @property string|null $cover_path
 * @property string|null $cover_disk
 * @property string|null $primary_color
 * @property string|null $thank_you_note
 * @property string|null $website
 * @property array<string, string>|null $social_links
 */
class RestaurantBrandProfile extends Model
{
    use BelongsToWorkspace;

    protected $fillable = [
        'workspace_id', 'brand_name', 'legal_business_name', 'registered_business_address',
        'logo_path', 'logo_disk', 'cover_path', 'cover_disk', 'primary_color', 'thank_you_note', 'website', 'social_links',
    ];

    protected function casts(): array
    {
        return ['social_links' => 'array'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function logoUrl(): ?string
    {
        return $this->assetUrl($this->logo_path, $this->logo_disk);
    }

    public function coverUrl(): ?string
    {
        return $this->assetUrl($this->cover_path, $this->cover_disk);
    }

    private function assetUrl(?string $path, ?string $disk): ?string
    {
        return is_string($path) && $path !== '' ? Storage::disk($disk ?: 'public')->url($path) : null;
    }
}
