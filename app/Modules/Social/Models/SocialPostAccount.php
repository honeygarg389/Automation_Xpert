<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One target account's outcome for one post.
 *
 * ⚠️ `platform_post_id` is the PUBLISHED post's id. `provider_container_id` is an
 * unpublished, still-processing upload that expires after 24 hours and may never
 * become a post — see InstagramSocialDriver. Deliberately separate columns:
 * overloading one would make "has this published?" unanswerable.
 *
 * `provider_upload_state` is the generic multi-step sibling of
 * provider_container_id — used ONLY when a single id cannot represent an
 * in-flight upload (a carousel's several child containers, a resumable
 * upload's session + byte offset). See the migration that added it for the
 * exact shape and why it generalizes across providers. A row uses at most one
 * of provider_container_id / provider_upload_state, never both.
 *
 * @property int $id
 * @property int $post_id
 * @property int $social_account_id
 * @property string $status
 * @property string|null $platform_post_id
 * @property string|null $provider_container_id
 * @property Carbon|null $container_created_at
 * @property array{phase?: string, items?: list<array<string, mixed>>, parent_id?: string|null, meta?: array<string, mixed>}|null $provider_upload_state
 * @property string|null $error
 * @property Carbon|null $published_at
 */
class SocialPostAccount extends Model
{
    protected $table = 'social_media_post_accounts';

    protected $fillable = ['post_id', 'social_account_id', 'status', 'platform_post_id', 'error', 'published_at', 'provider_container_id', 'container_created_at', 'provider_upload_state'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'container_created_at' => 'datetime', 'provider_upload_state' => 'array'];
    }

    public function post()
    {
        return $this->belongsTo(SocialPost::class, 'post_id');
    }

    public function account()
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
