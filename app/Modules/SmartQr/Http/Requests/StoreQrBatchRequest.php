<?php

namespace App\Modules\SmartQr\Http\Requests;

use App\Modules\SmartQr\Models\SmartQrBatch;
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
            'quantity' => ['required', 'integer', 'min:1', 'max:'.SmartQrBatch::MAX_QUANTITY],

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
            // ⚠️ 1900 KB, DELIBERATELY UNDER php.ini's upload_max_filesize.
            //
            // This was 2048 to match the platform logo, and 2048 KB is EXACTLY
            // 2M — the same value as upload_max_filesize on the machines seen
            // so far. Two limits at the identical threshold means PHP always
            // rejects first, and its rejection carries no size information at
            // all: the admin is told "The logo failed to upload." and cannot
            // tell an oversized file from a broken one. The rule was correct
            // and unreachable.
            //
            // 1900 KB leaves ~148 KB of headroom, so THIS rule fires first and
            // says how big is too big. It is not a security boundary — PHP's
            // limit remains the real ceiling — it is the boundary that can
            // produce a usable message.
            //
            // The size itself still buys nothing above this: a logo is
            // composited at 22% of 1024px, so ~225px square is the useful
            // ceiling, and a larger upload costs the queue memory 500 times
            // over.
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:1900'],
        ];
    }

    /**
     * ═══ ⚠️ "The logo failed to upload." IS NOT A SIZE MESSAGE ════════════
     *
     * That string is Laravel's `uploaded` rule, added implicitly by `file`. It
     * fires when PHP itself rejected the upload — `UploadedFile::isValid()` is
     * false — so validation never sees a file at all, and the `max:` message
     * below it never runs.
     *
     * ⚠️ IT USED TO BE UNREACHABLE, MEASURED:
     *
     *     php.ini upload_max_filesize = 2M     = 2,097,152 bytes
     *     the rule, when it was max:2048       = 2,097,152 bytes   ← identical
     *     the rule now, max:1900               = 1,945,600 bytes   ← fires first
     *
     * At 2048 the two thresholds were exactly equal, so any file large enough
     * to trip the rule had already been discarded by PHP and the admin was
     * told "failed to upload" instead of being told the size limit. Lowering
     * the rule to 1900 is what makes the size message reachable at all.
     *
     * This override still matters, because PHP's limit is only ONE of the
     * conditions that produce it: a truncated POST, an unwritable temp
     * directory, or a file over the ini limit on a machine configured lower
     * than 1900 KB all land here. The message names the live server figure so
     * the cause is legible in each case.
     *
     * ⚠️ Raising `upload_max_filesize` itself is a server-config decision and
     * is deliberately NOT made in application code.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.uploaded' => __(
                'The logo could not be uploaded. It is most likely larger than the '
                .':limit the server accepts — try a smaller image.',
                ['limit' => ini_get('upload_max_filesize')]
            ),
        ];
    }
}
