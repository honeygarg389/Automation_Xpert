<?php

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $waba_id
 * @property string $name
 * @property string $language
 * @property string $category
 * @property string $status
 * @property array<int, array<string, mixed>>|null $components
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WhatsappTemplate extends Model
{
    protected $table = 'whatsapp_templates';

    protected $fillable = [
        'workspace_id', 'waba_id', 'name', 'language', 'category',
        'status', 'components', 'rejection_reason', 'meta_template_id',
    ];

    protected function casts(): array
    {
        return ['components' => 'array'];
    }
}
