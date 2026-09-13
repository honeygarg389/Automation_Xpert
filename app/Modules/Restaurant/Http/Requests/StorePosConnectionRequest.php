<?php

namespace App\Modules\Restaurant\Http\Requests;

use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Support\IpAllowlistNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Phase 1C — creating a sandbox Petpooja connection.
 *
 * Authorization is the route's `permission:manage_pos_connections`
 * middleware, not this class — one gate, in the place the route table shows
 * (same convention as StoreQrBatchRequest).
 *
 * ⚠️ THE ROOT-CAUSE FIX: this used to infer "existing outlet" vs "create
 * new outlet" from WHICH of outlet_id/new_outlet_name happened to be
 * present (`isset($data['outlet_id'])` in the controller) rather than from
 * an explicit mode the client declared. That was a real, live defect —
 * measured in the working database: an attempt to create outlet "Burger
 * King" instead attached a new connection to the EXISTING "Food Court"
 * outlet, because a stale `outlet_id` from an earlier form interaction was
 * still present alongside the freshly-typed `new_outlet_name`, and
 * `isset()` on the stale value silently won. An explicit `mode` field
 * removes the ambiguity entirely: the controller trusts ONLY `mode` to
 * decide which branch to take, and the other mode's fields are never read
 * regardless of what they contain — see PosConnectionController::store().
 */
class StorePosConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalizes allowed_ips BEFORE the `ip` rule runs against each entry —
     * trims whitespace, drops blank lines, deduplicates. Without this, a
     * value with trailing whitespace (easy to introduce from a textarea) or
     * a literal blank line would fail the `ip` rule with a confusing
     * per-index error instead of simply being ignored or accepted.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('allowed_ips')) {
            $this->merge(['allowed_ips' => IpAllowlistNormalizer::normalize($this->input('allowed_ips'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['existing', 'new'])],
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],

            // required_if:mode,existing — NOT required_without:new_outlet_name.
            // The old required_without version is exactly what let a stale
            // outlet_id silently satisfy validation even in 'new' mode (it
            // was never absent, so required_without never fired either way).
            // Tying the requirement to the EXPLICIT mode closes that gap.
            'outlet_id' => [
                Rule::requiredIf(fn () => $this->input('mode') === 'existing'),
                'nullable', 'integer',
                Rule::exists('restaurant_outlets', 'id')->where('workspace_id', $this->input('workspace_id')),
                function ($attribute, $value, $fail) {
                    if ($this->input('mode') !== 'existing' || $value === null) {
                        return;
                    }
                    $outlet = RestaurantOutlet::find($value);
                    if ($outlet && $outlet->status !== RestaurantOutlet::STATUS_ACTIVE) {
                        $fail('The selected outlet is not active.');
                    } elseif ($outlet && $outlet->hasNonArchivedConnection()) {
                        $fail('The selected outlet already has an active Petpooja connection. Open its existing configuration instead.');
                    }
                },
            ],
            'new_outlet_name' => [
                Rule::requiredIf(fn () => $this->input('mode') === 'new'),
                'nullable', 'string', 'max:128',
            ],
            'new_outlet_address' => ['nullable', 'string', 'max:512'],
            'new_outlet_timezone' => ['nullable', 'string', 'max:64'],

            // Provider is NEVER accepted from the request — it is fixed to
            // 'petpooja' by the controller, not by validating a submitted
            // value, so there is no field here for it at all.
            'external_ref' => [
                'required', 'string', 'max:64',
                Rule::unique('pos_connections', 'external_ref')->where('provider', PosConnection::PROVIDER_PETPOOJA),
            ],

            'allowed_ips' => ['nullable', 'array'],
            'allowed_ips.*' => ['string', 'ip'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // The Laravel default ("The external ref has already been
            // taken.") is a database-shaped message about a column name the
            // admin never sees on the form — this is the "friendly error
            // rather than a database exception" the task asked for.
            'external_ref.unique' => 'This Petpooja restID is already connected to another outlet. Each restID may only be used once across all workspaces.',
            'outlet_id.exists' => 'The selected outlet does not belong to the selected workspace.',
            'allowed_ips.*.ip' => 'Each allowed IP must be a valid IP address. Check for typos and remove anything that is not a plain IP (CIDR ranges are not supported).',
        ];
    }
}
