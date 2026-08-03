<?php

namespace App\Modules\Social\Models;

use Illuminate\Database\Eloquent\Model;

class SocialAccount extends Model
{
    protected $table = 'social_media_accounts';

    protected $fillable = ['workspace_id', 'network', 'account_id', 'name', 'picture_url', 'access_token', 'refresh_token', 'token_expires_at', 'scopes', 'meta', 'active'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'meta' => 'array',
            'active' => 'boolean',
            'token_expires_at' => 'datetime',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    public function posts()
    {
        return $this->belongsToMany(SocialPost::class, 'social_media_post_accounts', 'social_account_id', 'post_id');
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }
}
