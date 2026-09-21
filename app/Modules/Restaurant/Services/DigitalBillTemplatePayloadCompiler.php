<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantDigitalBillDelivery;
use App\Modules\Restaurant\Support\RestaurantOutboundDecision;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use Illuminate\Routing\UrlGenerator;

/** Compiles only the one safe V1 Meta URL-button parameter; never persists it. */
final class DigitalBillTemplatePayloadCompiler
{
    public const REASON_BODY_VARIABLES = 'template_body_variables';

    public const REASON_URL_BUTTON_MISSING = 'template_url_button_missing';

    public const REASON_URL_BUTTON_MULTIPLE = 'template_url_button_multiple';

    public const REASON_URL_BUTTON_STATIC = 'template_url_button_static';

    public const REASON_URL_BUTTON_INVALID = 'template_url_button_invalid';

    public const REASON_PUBLIC_URL_INVALID = 'bill_public_url_invalid';

    public function __construct(private readonly UrlGenerator $urls) {}

    /** @return array<int, array<string, mixed>>|RestaurantOutboundDecision */
    public function compile(WhatsappTemplate $template, RestaurantBill $bill, int $workspaceId): array|RestaurantOutboundDecision
    {
        $token = $bill->public_token;
        if (! is_string($token) || preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return RestaurantOutboundDecision::block(RestaurantDigitalBillDelivery::PURPOSE_DIGITAL_BILL, 'bill_public_token_missing', $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id);
        }
        // Build the route through Laravel's registered route generator, never
        // from a request host. Meta's approved template owns the fixed host;
        // this compiler intentionally passes only this route's opaque suffix.
        if ($this->urls->route('public.restaurant.bills.show', ['token' => $token], false) !== '/b/'.$token) {
            return RestaurantOutboundDecision::block(RestaurantDigitalBillDelivery::PURPOSE_DIGITAL_BILL, self::REASON_PUBLIC_URL_INVALID, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id);
        }

        $urlButtons = [];
        foreach ($template->components ?? [] as $rawComponent) {
            // Components are persisted JSON from Meta and may be malformed or
            // stale. Treat anything not shaped as a component as unusable,
            // rather than allowing PHP offset access to turn a bad template
            // into a worker exception at the provider boundary.
            $component = $this->objectOrNull($rawComponent);
            if ($component === null) {
                continue;
            }
            if (($component['type'] ?? null) === 'BODY' && preg_match('/{{\s*\d+\s*}}/', (string) ($component['text'] ?? '')) === 1) {
                return RestaurantOutboundDecision::block(RestaurantDigitalBillDelivery::PURPOSE_DIGITAL_BILL, self::REASON_BODY_VARIABLES, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id);
            }
            if (($component['type'] ?? null) !== 'BUTTONS') {
                continue;
            }
            foreach ($this->listOrEmpty($component['buttons'] ?? null) as $index => $rawButton) {
                $button = $this->objectOrNull($rawButton);
                if ($button !== null && ($button['type'] ?? null) === 'URL') {
                    $urlButtons[] = [$index, $button];
                }
            }
        }
        if ($urlButtons === []) {
            return RestaurantOutboundDecision::block(RestaurantDigitalBillDelivery::PURPOSE_DIGITAL_BILL, self::REASON_URL_BUTTON_MISSING, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id);
        }
        if (count($urlButtons) !== 1) {
            return RestaurantOutboundDecision::block(RestaurantDigitalBillDelivery::PURPOSE_DIGITAL_BILL, self::REASON_URL_BUTTON_MULTIPLE, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id);
        }
        [$index, $button] = $urlButtons[0];
        $url = $button['url'] ?? null;
        if (! is_string($url) || ! str_contains($url, '{{1}}')) {
            return RestaurantOutboundDecision::block(RestaurantDigitalBillDelivery::PURPOSE_DIGITAL_BILL, self::REASON_URL_BUTTON_STATIC, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id);
        }
        if ($url !== 'https://automationxpert.in/b/{{1}}') {
            return RestaurantOutboundDecision::block(RestaurantDigitalBillDelivery::PURPOSE_DIGITAL_BILL, self::REASON_URL_BUTTON_INVALID, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id);
        }

        return [['type' => 'button', 'sub_type' => 'url', 'index' => (string) $index, 'parameters' => [['type' => 'text', 'text' => $token]]]];
    }

    /** @return array<string, mixed>|null */
    private function objectOrNull(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }

    /** @return array<int|string, mixed> */
    private function listOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
