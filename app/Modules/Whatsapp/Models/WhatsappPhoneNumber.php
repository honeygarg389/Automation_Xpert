<?php

namespace App\Modules\Whatsapp\Models;

use App\Support\Concerns\MasksDemoData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One WhatsApp business phone number belonging to a WhatsappBusinessAccount.
 *
 * ⚠️ @property annotations added following the decision recorded on
 * ChannelAccount for BUG-002: annotate properly rather than let
 * `property.notFound` accumulate. `checkModelProperties` is on and larastan
 * cannot infer columns for this model.
 *
 * Types are read from SHOW COLUMNS on `whatsapp_phone_numbers`, not guessed.
 * This model declares NO casts, so every type below is the raw column type —
 * there is no post-cast divergence to account for here, unlike
 * WhatsappBusinessAccount where `credentials` and `webhook_verify_token` are
 * encrypted.
 *
 * ⚠️ `display_phone`, `verified_name` and `requested_verified_name` are masked
 * at read time by MasksDemoData in demo mode. That changes the VALUE, never the
 * type — they remain `string|null` — so the annotations below stay true in both
 * modes. Do not narrow them on the assumption a real number is always present.
 *
 * ⚠️ The varchar columns are annotated `string`, not literal unions, even where
 * the values are effectively enumerated in practice — `quality_rating`
 * (GREEN/YELLOW/RED), `code_verification_status`, `name_status`, `account_mode`.
 * The DATABASE does not constrain them: they are plain varchars fed verbatim
 * from Meta's API, which is free to add a value we have never seen. A literal
 * union here would be a lie the schema does not support, and would fail the
 * first time Meta ships a new rating. This is deliberate looseness, matching the
 * `$status` decision on WhatsappBusinessAccount.
 *
 * @property int $id
 * @property int $waba_id_fk
 * @property string $phone_number_id
 * @property string|null $display_phone
 * @property string|null $verified_name
 * @property string|null $quality_rating
 * @property string|null $messaging_limit_tier
 * @property string|null $code_verification_status
 * @property string|null $name_status
 * @property string|null $requested_verified_name
 * @property string|null $account_mode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WhatsappPhoneNumber extends Model
{
    use MasksDemoData;

    protected $table = 'whatsapp_phone_numbers';

    protected $fillable = [
        'waba_id_fk',
        'phone_number_id',
        'display_phone',
        'verified_name',
        'quality_rating',
        'messaging_limit_tier',
        'code_verification_status',
        'name_status',
        'requested_verified_name',
        'account_mode',
    ];

    /**
     * Hide the connected business number / verified name in demo mode.
     *
     * @return array<string, string>
     */
    protected function demoMask(): array
    {
        return [
            'display_phone' => 'phone',
            'verified_name' => 'name',
            'requested_verified_name' => 'name',
        ];
    }

    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsappBusinessAccount::class, 'waba_id_fk');
    }
}
