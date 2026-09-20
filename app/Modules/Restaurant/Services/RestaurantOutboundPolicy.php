<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Support\RestaurantOutboundDecision;
use App\Modules\Restaurant\Support\RestaurantOutboundPurpose;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Support\WorkspaceContext;

/**
 * Fail-closed eligibility gate for future Petpooja customer communications.
 *
 * This service is deliberately read-only. A future dispatch path must call it
 * immediately before dispatch and again immediately before delivery; this
 * class neither dispatches nor sends anything itself.
 */
final class RestaurantOutboundPolicy
{
    public const REASON_UNKNOWN_PURPOSE = 'unknown_purpose';

    public const REASON_WORKSPACE_CONTEXT_MISSING = 'workspace_context_missing';

    public const REASON_RESOURCE_WORKSPACE_MISMATCH = 'resource_workspace_mismatch';

    public const REASON_BILL_MISSING = 'bill_missing';

    public const REASON_BILL_OUTLET_MISSING = 'bill_outlet_missing';

    public const REASON_BILL_CONTACT_MISSING = 'bill_contact_missing';

    public const REASON_BILL_NOT_SUCCESSFUL = 'bill_not_successful';

    public const REASON_OUTLET_INACTIVE = 'outlet_inactive';

    public const REASON_DIGITAL_BILL_DISABLED = 'digital_bill_disabled';

    public const REASON_FEEDBACK_REQUEST_DISABLED = 'feedback_request_disabled';

    public const REASON_CONTACT_PHONE_MISSING = 'contact_phone_missing';

    public const REASON_WHATSAPP_OPTED_OUT = 'whatsapp_opted_out';

    public const REASON_DIGITAL_BILL_OPTED_OUT = 'digital_bill_opted_out';

    public const REASON_WHATSAPP_SENDER_NOT_READY = 'whatsapp_sender_not_ready';

    public const REASON_TEMPLATE_NOT_APPROVED = 'template_not_approved';

    /**
     * Re-loads every mutable record in the current trusted workspace before a
     * future delivery caller can act. Callers supply only record identities;
     * caller-supplied models, workspace IDs and payload values are ignored.
     */
    public function evaluate(
        string $purpose,
        int $billId,
        string $senderPhoneNumberId,
        int $templateId,
    ): RestaurantOutboundDecision {
        if (! RestaurantOutboundPurpose::isSupported($purpose)) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_UNKNOWN_PURPOSE, billId: $billId);
        }

        $workspaceId = WorkspaceContext::id();
        if ($workspaceId === null) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_WORKSPACE_CONTEXT_MISSING, billId: $billId);
        }

        /** @var RestaurantBill|null $bill */
        $bill = RestaurantBill::query()->find($billId);
        if ($bill === null) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_BILL_MISSING, $workspaceId, $billId);
        }

        if ((int) $bill->workspace_id !== $workspaceId) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_RESOURCE_WORKSPACE_MISMATCH, $workspaceId, $billId);
        }

        if ($bill->outlet_id === null) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_BILL_OUTLET_MISSING, $workspaceId, $bill->id);
        }

        /** @var RestaurantOutlet|null $outlet */
        $outlet = RestaurantOutlet::query()->find($bill->outlet_id);
        if ($outlet === null) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_BILL_OUTLET_MISSING, $workspaceId, $bill->id, $bill->outlet_id);
        }

        if ((int) $outlet->workspace_id !== $workspaceId) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_RESOURCE_WORKSPACE_MISMATCH, $workspaceId, $bill->id, $outlet->id);
        }

        if ($bill->contact_id === null) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_BILL_CONTACT_MISSING, $workspaceId, $bill->id, $outlet->id);
        }

        /** @var Contact|null $contact */
        $contact = Contact::query()->find($bill->contact_id);
        if ($contact === null) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_BILL_CONTACT_MISSING, $workspaceId, $bill->id, $outlet->id, $bill->contact_id);
        }

        if ((int) $contact->workspace_id !== $workspaceId) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_RESOURCE_WORKSPACE_MISMATCH, $workspaceId, $bill->id, $outlet->id, $contact->id);
        }

        if ($bill->source_order_status !== 'Success') {
            return RestaurantOutboundDecision::block($purpose, self::REASON_BILL_NOT_SUCCESSFUL, $workspaceId, $bill->id, $outlet->id, $contact->id);
        }

        if ($outlet->status !== RestaurantOutlet::STATUS_ACTIVE) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_OUTLET_INACTIVE, $workspaceId, $bill->id, $outlet->id, $contact->id);
        }

        if ($purpose === RestaurantOutboundPurpose::DIGITAL_BILL && ! $outlet->digital_bill_enabled) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_DIGITAL_BILL_DISABLED, $workspaceId, $bill->id, $outlet->id, $contact->id);
        }

        if ($purpose === RestaurantOutboundPurpose::FEEDBACK_REQUEST && ! $outlet->feedback_request_enabled) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_FEEDBACK_REQUEST_DISABLED, $workspaceId, $bill->id, $outlet->id, $contact->id);
        }

        if (! $this->hasUsablePhone($contact->phone_e164)) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_CONTACT_PHONE_MISSING, $workspaceId, $bill->id, $outlet->id, $contact->id);
        }

        if ($contact->getAttribute('whatsapp_opted_out_at') !== null) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_WHATSAPP_OPTED_OUT, $workspaceId, $bill->id, $outlet->id, $contact->id);
        }

        if ($purpose === RestaurantOutboundPurpose::DIGITAL_BILL && $contact->getAttribute('digital_bill_opted_out_at') !== null) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_DIGITAL_BILL_OPTED_OUT, $workspaceId, $bill->id, $outlet->id, $contact->id);
        }

        $sender = $this->readySender($senderPhoneNumberId, $workspaceId);
        if ($sender === null) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_WHATSAPP_SENDER_NOT_READY, $workspaceId, $bill->id, $outlet->id, $contact->id);
        }

        if (! $this->isApprovedTemplateForSender($templateId, $workspaceId, $sender->waba_id)) {
            return RestaurantOutboundDecision::block($purpose, self::REASON_TEMPLATE_NOT_APPROVED, $workspaceId, $bill->id, $outlet->id, $contact->id);
        }

        return RestaurantOutboundDecision::allow($purpose, $workspaceId, $bill->id, $outlet->id, $contact->id);
    }

    private function hasUsablePhone(?string $phone): bool
    {
        return is_string($phone) && preg_match('/^\\+[1-9]\\d{6,14}$/', trim($phone)) === 1;
    }

    /**
     * This mirrors CloudApiClient::forPhoneNumber()'s ownership/readiness
     * boundary without constructing a provider client or invoking its logging
     * fallback. Evaluating eligibility must remain observational.
     */
    private function readySender(string $phoneNumberId, int $workspaceId): ?WhatsappBusinessAccount
    {
        /** @var WhatsappPhoneNumber|null $sender */
        $sender = WhatsappPhoneNumber::query()
            ->where('phone_number_id', $phoneNumberId)
            ->whereHas('businessAccount', fn ($query) => $query
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active'))
            ->first();

        if ($sender === null) {
            return null;
        }

        /** @var WhatsappBusinessAccount|null $businessAccount */
        $businessAccount = $sender->businessAccount()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->first();

        if ($businessAccount === null || ! filled($businessAccount->accessToken())) {
            return null;
        }

        return $businessAccount;
    }

    private function isApprovedTemplateForSender(int $templateId, int $workspaceId, string $wabaId): bool
    {
        return WhatsappTemplate::query()
            ->whereKey($templateId)
            ->where('workspace_id', $workspaceId)
            ->where('waba_id', $wabaId)
            ->where('status', 'APPROVED')
            ->exists();
    }
}
