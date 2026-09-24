<?php

namespace App\Modules\Restaurant\Services;

use App\Models\Workspace;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantBrandProfile;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Shared\Models\Contact;

/**
 * The only translation from provider-shaped bill JSON to the public page.
 * Keep this an allow-list: raw POS payloads are not a presentation contract.
 */
final class PublicRestaurantBillPresenter
{
    /** @return array<string, mixed> */
    public function present(RestaurantBill $bill, ?RestaurantOutlet $outlet, ?RestaurantBrandProfile $brand, Workspace $workspace, ?Contact $contact): array
    {
        $primaryColor = is_string($brand?->primary_color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $brand->primary_color)
            ? $brand->primary_color
            : '#1f2937';

        return [
            'brand' => [
                'name' => $this->text($brand?->brand_name, 128) ?? $this->text($workspace->name, 128) ?? 'Restaurant',
                'logo_url' => $brand?->logoUrl(),
                'cover_url' => $brand?->coverUrl(),
                'primary_color' => $primaryColor,
            ],
            'outlet' => [
                'name' => $this->text($outlet?->name, 128),
                'address' => $this->text($outlet?->address, 512),
                'phone' => $this->text($outlet?->public_phone, 32),
                'website' => $this->safeUrl($outlet?->public_website) ?? $this->safeUrl($brand?->website),
            ],
            'bill' => [
                'number' => $this->text($bill->external_order_id, 64),
                'placed_at' => $bill->placed_at?->format('d M Y, h:i A'),
                'order_type' => $this->text($bill->source_order_type, 64),
                'status' => $this->text($bill->source_order_status, 32),
                'items' => $this->items($bill->order_items),
                'taxes' => $this->amountRows($bill->taxes),
                'discounts' => $this->amountRows($bill->discounts),
                'tax_total' => $this->amount($bill->tax_total),
                'discount_total' => $this->amount($bill->discount_total),
                'total' => $this->amount($bill->total),
                'currency_code' => preg_match('/^[A-Z]{3}$/', (string) $bill->currency_code) ? $bill->currency_code : null,
                'currency_symbol' => $this->text($bill->currency_symbol, 12),
            ],
            'thank_you_note' => $this->text($brand?->thank_you_note, 1000),
            'social_links' => $this->socialLinks($brand?->social_links),
            // This is intentionally limited to the one contact already linked
            // to this bearer-token bill. It never accepts a contact id from the
            // browser and it does not expose POS-supplied customer data.
            'customer_profile' => $contact === null ? null : [
                'first_name' => $this->text($contact->first_name, 100),
                'last_name' => $this->text($contact->last_name, 100),
                'email' => $this->text($contact->email, 255),
                'birthday' => $contact->birthday?->format('Y-m-d'),
                'postal_code' => $this->text($contact->postal_code, 20),
                'gender' => in_array($contact->gender, Contact::GENDERS, true) ? $contact->gender : null,
                'phone' => $this->text($contact->phone_e164, 32),
            ],
            'disclaimer' => 'This is a system-generated digital copy.',
        ];
    }

    /**
     * @param  array<int, mixed>|null  $rows
     * @return list<array{name: string, quantity: float|null, unit_price: float|null, total: float|null}>
     */
    private function items(?array $rows): array
    {
        $items = [];
        foreach ($rows ?? [] as $row) {
            if (! is_array($row) || ($name = $this->text($row['name'] ?? null, 160)) === null) {
                continue;
            }
            $items[] = [
                'name' => $name,
                'quantity' => $this->amount($row['quantity'] ?? null),
                'unit_price' => $this->amount($row['price'] ?? null),
                'total' => $this->amount($row['total'] ?? null),
            ];
        }

        return $items;
    }

    /**
     * @param  array<int, mixed>|null  $rows
     * @return list<array{name: string, amount: float}>
     */
    private function amountRows(?array $rows): array
    {
        $result = [];
        foreach ($rows ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $this->text($row['name'] ?? $row['label'] ?? null, 160);
            $amount = $this->amount($row['amount'] ?? $row['total'] ?? null);
            if ($name !== null && $amount !== null) {
                $result[] = ['name' => $name, 'amount' => $amount];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string>|null  $links
     * @return array<string, string>
     */
    private function socialLinks(?array $links): array
    {
        $approved = [];
        foreach (['instagram', 'facebook', 'google', 'x', 'youtube'] as $key) {
            $url = $this->safeUrl($links[$key] ?? null);
            if ($url !== null) {
                $approved[$key] = $url;
            }
        }

        return $approved;
    }

    private function safeUrl(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 255 || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $scheme = parse_url($value, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true) ? $value : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function amount(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }
}
