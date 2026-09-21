<?php

namespace App\Modules\Restaurant\Services;

use App\Models\AdminUser;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Restaurant\Models\RestaurantBrandProfile;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Services\AuditLogService;
use App\Services\StorageManager;
use App\Support\Files\SafeUploadExtension;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RestaurantBrandProfileService
{
    public function __construct(private readonly AuditLogService $audit, private readonly StorageManager $storage) {}

    /** @param array<string, mixed> $attributes */
    public function updateProfile(Workspace $workspace, array $attributes, ?UploadedFile $logo, ?UploadedFile $cover, AdminUser|User $actor): RestaurantBrandProfile
    {
        return DB::transaction(function () use ($workspace, $attributes, $logo, $cover, $actor): RestaurantBrandProfile {
            $profile = RestaurantBrandProfile::query()->firstOrNew(['workspace_id' => $workspace->id]);
            $old = $profile->exists ? $this->auditableProfile($profile) : null;
            if ($logo !== null) {
                [$attributes['logo_path'], $attributes['logo_disk']] = $this->store($logo, 'restaurant-branding/logos');
            }
            if ($cover !== null) {
                [$attributes['cover_path'], $attributes['cover_disk']] = $this->store($cover, 'restaurant-branding/covers');
            }
            $profile->fill($attributes);
            $profile->workspace_id = $workspace->id;
            $profile->save();
            $new = $this->auditableProfile($profile);

            if ($actor instanceof AdminUser) {
                $this->audit->logAdmin('restaurant.brand_profile.updated', RestaurantBrandProfile::class, $profile->id, ['workspace_id' => $workspace->id], $actor, oldValues: $old, newValues: $new, workspaceId: $workspace->id);
            } else {
                $this->audit->log('restaurant.brand_profile.updated', $profile, $old, $new, workspaceId: $workspace->id);
            }

            return $profile->refresh();
        });
    }

    public function updateOutletPublicContact(RestaurantOutlet $outlet, ?string $phone, ?string $website, AdminUser|User $actor): RestaurantOutlet
    {
        return DB::transaction(function () use ($outlet, $phone, $website, $actor): RestaurantOutlet {
            $old = $this->auditableOutletPublicContact($outlet);
            $outlet->update(['public_phone' => $phone, 'public_website' => $website]);
            $new = $this->auditableOutletPublicContact($outlet);
            if ($actor instanceof AdminUser) {
                $this->audit->logAdmin('restaurant.outlet.public_contact_updated', RestaurantOutlet::class, $outlet->id, ['workspace_id' => $outlet->workspace_id], $actor, oldValues: $old, newValues: $new, workspaceId: $outlet->workspace_id);
            } else {
                $this->audit->log('restaurant.outlet.public_contact_updated', $outlet, $old, $new, workspaceId: $outlet->workspace_id);
            }

            return $outlet->refresh();
        });
    }

    /** @param array{name:string,address:?string,public_phone:?string,public_email:?string,public_website:?string,gstin:?string,fssai_number:?string} $attributes */
    public function updateOutletProfile(RestaurantOutlet $outlet, array $attributes, AdminUser|User $actor): RestaurantOutlet
    {
        return DB::transaction(function () use ($outlet, $attributes, $actor): RestaurantOutlet {
            $old = $this->auditableOutletProfile($outlet);
            $outlet->fill($attributes)->save();
            $new = $this->auditableOutletProfile($outlet->fresh());
            $meta = ['workspace_id' => $outlet->workspace_id, 'outlet_id' => $outlet->id];
            if ($actor instanceof AdminUser) {
                $this->audit->logAdmin('restaurant.outlet.profile_updated', RestaurantOutlet::class, $outlet->id, $meta, $actor, oldValues: $old, newValues: $new, workspaceId: $outlet->workspace_id);
            } else {
                $this->audit->log('restaurant.outlet.profile_updated', $outlet, $old, $new, workspaceId: $outlet->workspace_id);
            }

            return $outlet->refresh();
        });
    }

    /** @return array{0:string,1:string} */
    private function store(UploadedFile $file, string $directory): array
    {
        $disk = $this->storage->diskName();
        $path = $this->storage->prefixedPath($directory.'/'.Str::uuid().'.'.SafeUploadExtension::for($file));
        $this->storage->disk()->putFileAs(dirname($path), $file, basename($path));

        return [$path, $disk];
    }

    /** @return array<string, mixed> */
    private function auditableProfile(RestaurantBrandProfile $profile): array
    {
        return [
            'brand_name' => $profile->brand_name,
            'legal_business_name' => $profile->legal_business_name,
            'has_registered_business_address' => $profile->registered_business_address !== null,
            'primary_color' => $profile->primary_color,
            'has_thank_you_note' => $profile->thank_you_note !== null,
            'has_website' => $profile->website !== null,
            'social_link_keys' => array_keys($profile->social_links ?? []),
            'has_logo' => $profile->logo_path !== null,
            'has_cover' => $profile->cover_path !== null,
        ];
    }

    /** @return array{name:?string,has_address:bool,has_public_phone:bool,has_public_email:bool,has_public_website:bool,has_gstin:bool,has_fssai_number:bool} */
    private function auditableOutletProfile(RestaurantOutlet $outlet): array
    {
        return [
            'name' => $outlet->name,
            'has_address' => $outlet->address !== null,
            'has_public_phone' => $outlet->public_phone !== null,
            'has_public_email' => $outlet->public_email !== null,
            'has_public_website' => $outlet->public_website !== null,
            'has_gstin' => $outlet->gstin !== null,
            'has_fssai_number' => $outlet->fssai_number !== null,
        ];
    }

    /** @return array{has_public_phone:bool,has_public_website:bool} */
    private function auditableOutletPublicContact(RestaurantOutlet $outlet): array
    {
        return [
            'has_public_phone' => $outlet->public_phone !== null,
            'has_public_website' => $outlet->public_website !== null,
        ];
    }
}
