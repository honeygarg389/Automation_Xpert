<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'is_secret', 'group'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean'];
    }

    public function getValueAttribute($value): ?string
    {
        $raw = $this->attributes['value'] ?? $value;
        if ($this->is_secret && $raw) {
            try {
                return Crypt::decryptString($raw);
            } catch (\Throwable) {
                return null;
            }
        }

        return $raw;
    }

    public function setValueAttribute($value): void
    {
        if ($this->is_secret && $value !== null && $value !== '') {
            $this->attributes['value'] = Crypt::encryptString($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }

    public static function get(string $key, $default = null)
    {
        $s = static::where('key', $key)->first();

        return $s ? $s->value : $default;
    }

    public static function set(string $key, $value, bool $isSecret = false, ?string $group = null): void
    {
        $s = static::firstOrNew(['key' => $key]);
        $s->value = $value;
        $s->is_secret = $isSecret;
        $s->group = $group;
        $s->save();
    }
}
