<?php

namespace App\Modules\SmartQr\Http\Requests;

use App\Modules\SmartQr\Rules\SerialRangeAvailable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §4 batch creation.
 *
 * Authorization is the route's `permission:manage_qr_batches` middleware, not
 * this class — one gate, in the place the route table shows.
 */
class StoreQrBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'batch_name' => ['required', 'string', 'max:255'],
            'batch_number' => ['required', 'string', 'max:64', Rule::unique('smart_qr_batches', 'batch_number')],

            // Uppercase alphanumeric: the prefix is PRINTED on the artwork, and
            // a lowercase or punctuated prefix produces serials an operator
            // cannot read back off a sticker reliably.
            'prefix' => ['required', 'string', 'max:16', 'regex:/^[A-Z0-9]+$/'],

            // 500 is the spec's example batch size (§4). The ceiling is here
            // rather than in the generator because the generator is chunked and
            // has no opinion about it.
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],

            // ⚠️ The gap slice 2 deferred here. See SerialRangeAvailable — it is
            // a TOCTOU check, and the unique index remains the guarantee.
            'serial_start' => [
                'required', 'integer', 'min:1',
                new SerialRangeAvailable(
                    (string) $this->input('prefix'),
                    (int) $this->input('quantity'),
                ),
            ],

            // ⚠️ R-3: a plain string, deliberately. The spec carries qr_type but
            // never enumerates its values, and the placement vocabulary was
            // decided after the spec was written — so it must be changeable
            // without a migration OR an enum rule to keep in step.
            'qr_type' => ['nullable', 'string', 'max:32'],
            'default_message' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // ═══ ⚠️ `file`, NOT `image` — AND THAT IS THE WHOLE RULE ══════
            //
            // SystemSettingsController::uploadLogo records the trap: Laravel
            // 12's `image` rule hardcodes jpg/jpeg/png/gif/bmp/webp and adds
            // svg only when passed `allow_svg` — so `image` OVERRIDES the
            // `mimes:` list rather than intersecting with it. Written as
            // ['image', 'mimes:png,jpg,jpeg'] this rule would still accept gif,
            // bmp and webp, and on Laravel <=10 it accepted SVG outright.
            //
            // `file` leaves `mimes:` as the only allow-list, so png/jpg/jpeg
            // means png/jpg/jpeg. The favicon rule is written this way for the
            // same reason. UploadExtensionSpoofingTest pins it.
            //
            // ⚠️ `mimes:` validates the SNIFFED extension. The extension the
            // file is STORED under comes from SafeUploadExtension::for(), never
            // from the client filename — see the controller.
            //
            // 2048 KB matches the platform logo. A logo is composited at 22% of
            // 1024px, so ~225px square is the useful ceiling anyway; a larger
            // upload buys nothing and costs the queue memory 500 times over.
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:2048'],
        ];
    }
}
