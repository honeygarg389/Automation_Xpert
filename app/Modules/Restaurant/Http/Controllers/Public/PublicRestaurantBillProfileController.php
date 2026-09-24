<?php

namespace App\Modules\Restaurant\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Shared\Models\Contact;
use App\Services\AuditLogService;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicRestaurantBillProfileController extends Controller
{
    public function __invoke(Request $request, string $token, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'birthday' => ['nullable', 'date_format:Y-m-d'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'gender' => ['nullable', Rule::in(Contact::GENDERS)],
        ]);

        $candidate = RestaurantBill::withoutWorkspaceScope('reason: public bill profile updates discover exactly one workspace from the opaque bill token before scoped contact access.')
            ->where('public_token', $token)->whereNull('public_access_revoked_at')->first();
        abort_unless($candidate !== null, 404);

        WorkspaceContext::for((int) $candidate->workspace_id, function () use ($candidate, $data, $audit): void {
            $bill = RestaurantBill::query()->with('contact')->find($candidate->id);
            abort_unless($bill?->contact !== null, 404);

            $contact = $bill->contact;
            $contact->fill($data);
            $changed = array_values(array_intersect(array_keys($contact->getDirty()), ['first_name', 'last_name', 'email', 'birthday', 'postal_code', 'gender']));
            if ($changed !== []) {
                $contact->save();
                // Metadata deliberately contains only identifiers and field names.
                $audit->logSystem('restaurant.public_bill_contact_profile_updated', $contact, (int) $bill->workspace_id, [
                    'restaurant_bill_id' => $bill->id,
                    'updated_fields' => $changed,
                ]);
            }
        });

        return redirect()->route('public.restaurant.bills.show', ['token' => $token])->with('profile_updated', true);
    }
}
