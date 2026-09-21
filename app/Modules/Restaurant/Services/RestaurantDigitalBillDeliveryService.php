<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Restaurant\Jobs\SendRestaurantDigitalBillJob;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantDigitalBillDelivery;
use App\Modules\Restaurant\Models\RestaurantDigitalBillDeliveryConfig;
use App\Modules\Restaurant\Support\RestaurantOutboundDecision;
use App\Modules\Restaurant\Support\RestaurantOutboundPurpose;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Services\AuditLogService;
use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Schedules and sends the sole idempotent, policy-gated Digital Bill. */
final class RestaurantDigitalBillDeliveryService
{
    public const REASON_CONFIG_MISSING = 'digital_bill_delivery_config_missing';

    public const REASON_CONFIG_INCOMPLETE = 'digital_bill_delivery_config_incomplete';

    public const REASON_PROVIDER_RESPONSE_INVALID = 'provider_response_invalid';

    public const REASON_PROVIDER_OUTCOME_UNKNOWN = 'provider_outcome_unknown';

    public const REASON_PRE_SEND_EVALUATION_FAILED = 'pre_send_evaluation_failed';

    public function __construct(
        private readonly RestaurantOutboundPolicy $policy,
        private readonly DigitalBillTemplatePayloadCompiler $compiler,
        private readonly AuditLogService $audit,
    ) {}

    public function schedule(int $billId): ?RestaurantDigitalBillDelivery
    {
        $workspaceId = WorkspaceContext::id();
        if ($workspaceId === null) {
            return null;
        }
        $bill = RestaurantBill::query()->find($billId);
        if ($bill === null) {
            return null;
        }

        try {
            return DB::transaction(function () use ($bill, $workspaceId): RestaurantDigitalBillDelivery {
                $existing = RestaurantDigitalBillDelivery::query()->where('restaurant_bill_id', $bill->id)->where('purpose', RestaurantDigitalBillDelivery::PURPOSE_DIGITAL_BILL)->lockForUpdate()->first();
                // A retry/correction must never turn a suppressed/failed/unknown
                // prior decision into an automatic second customer send.
                if ($existing !== null) {
                    return $existing;
                }

                [$decision, $config] = $this->eligibility($bill, $workspaceId);
                $delivery = new RestaurantDigitalBillDelivery(['workspace_id' => $workspaceId, 'restaurant_bill_id' => $bill->id, 'outlet_id' => $bill->outlet_id, 'purpose' => RestaurantDigitalBillDelivery::PURPOSE_DIGITAL_BILL]);
                $delivery->digital_bill_delivery_config_id = $config?->id;
                if (! $decision->allowed) {
                    $delivery->fill(['status' => RestaurantDigitalBillDelivery::STATUS_SUPPRESSED, 'reason_code' => $decision->reasonCode, 'suppressed_at' => now(), 'provider_attempt_started_at' => null])->save();
                    $this->auditSuppressed($delivery);

                    return $delivery->refresh();
                }

                $delivery->fill(['outlet_id' => $bill->outlet_id, 'status' => RestaurantDigitalBillDelivery::STATUS_PENDING, 'reason_code' => null, 'suppressed_at' => null])->save();
                DB::afterCommit(fn () => SendRestaurantDigitalBillJob::dispatch($delivery->id));

                return $delivery->refresh();
            });
        } catch (QueryException $exception) {
            // The unique ledger constraint is the final arbiter when two
            // workers schedule the same bill concurrently. Return its winner
            // instead of allowing a duplicate send path.
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            return RestaurantDigitalBillDelivery::query()
                ->where('restaurant_bill_id', $bill->id)
                ->where('purpose', RestaurantDigitalBillDelivery::PURPOSE_DIGITAL_BILL)
                ->firstOrFail();
        }
    }

    public function send(int $deliveryId): void
    {
        $workspaceId = WorkspaceContext::id();
        if ($workspaceId === null) {
            return;
        }
        /** @var RestaurantDigitalBillDelivery|null $delivery */
        $delivery = RestaurantDigitalBillDelivery::query()->find($deliveryId);
        if ($delivery === null || $delivery->status !== RestaurantDigitalBillDelivery::STATUS_PENDING) {
            return;
        }
        if (RestaurantDigitalBillDelivery::query()->whereKey($delivery->id)->where('status', RestaurantDigitalBillDelivery::STATUS_PENDING)->update(['status' => RestaurantDigitalBillDelivery::STATUS_SENDING, 'updated_at' => now()]) !== 1) {
            return;
        }

        try {
            /** @var RestaurantBill|null $bill */
            $bill = RestaurantBill::query()->find($delivery->restaurant_bill_id);
            if ($bill === null) {
                $this->suppress($delivery, 'bill_missing');

                return;
            }
            [$decision, $config] = $this->eligibility($bill, $workspaceId);
            if (! $decision->allowed || $config === null) {
                $this->suppress($delivery, $decision->reasonCode ?? self::REASON_CONFIG_MISSING);

                return;
            }

            /** @var WhatsappTemplate|null $template */
            $template = WhatsappTemplate::query()->find($config->whatsapp_template_id);
            /** @var WhatsappPhoneNumber|null $sender */
            $sender = WhatsappPhoneNumber::query()->find($config->whatsapp_phone_number_id);
            if ($template === null || $sender === null) {
                $this->suppress($delivery, self::REASON_CONFIG_INCOMPLETE);

                return;
            }
            $components = $this->compiler->compile($template, $bill, $workspaceId);
            if ($components instanceof RestaurantOutboundDecision) {
                $this->suppress($delivery, $components->reasonCode ?? self::REASON_CONFIG_INCOMPLETE);

                return;
            }

            $contact = $bill->contact;
            if ($contact === null || ! is_string($contact->phone_e164)) {
                $this->suppress($delivery, RestaurantOutboundPolicy::REASON_CONTACT_PHONE_MISSING);

                return;
            }
            $account = $sender->businessAccount;
            if ($account === null || ! filled($account->accessToken())) {
                $this->suppress($delivery, RestaurantOutboundPolicy::REASON_WHATSAPP_SENDER_NOT_READY);

                return;
            }
        } catch (Throwable) {
            // Nothing has crossed the provider boundary: fail closed rather
            // than marking a delivery ambiguous or silently leaving it
            // `sending`. A later deliberate retry/recovery workflow may
            // inspect this terminal state; this job never retries itself.
            $this->failBeforeProvider($delivery);

            return;
        }

        $delivery->forceFill(['provider_attempt_started_at' => now(), 'attempt_count' => $delivery->attempt_count + 1, 'updated_at' => now()])->save();
        try {
            $response = (new CloudApiClient($sender->phone_number_id, $account->accessToken()))->sendTemplate($contact->phone_e164, $template->name, $template->language, $components);
            $messageId = $response->successful() ? data_get($response->json(), 'messages.0.id') : null;
            if (! is_string($messageId) || $messageId === '') {
                $this->outcomeUnknown($delivery, self::REASON_PROVIDER_RESPONSE_INVALID);

                return;
            }
            $delivery->forceFill(['status' => RestaurantDigitalBillDelivery::STATUS_SENT, 'provider_message_id' => $messageId, 'sent_at' => now(), 'reason_code' => null])->save();
            $this->audit->logSystem('restaurant.digital_bill.sent', $delivery, $workspaceId, ['restaurant_bill_id' => $delivery->restaurant_bill_id, 'outlet_id' => $delivery->outlet_id, 'delivery_id' => $delivery->id]);
        } catch (Throwable) {
            // A provider call may have reached Meta. Never retry or resend an ambiguous attempt.
            $this->outcomeUnknown($delivery, self::REASON_PROVIDER_OUTCOME_UNKNOWN);
        }
    }

    /** A killed worker after the provider boundary must never be retried. */
    public function markStalledAttemptsOutcomeUnknown(int $minutes): int
    {
        $minutes = max(1, $minutes);
        $now = now();

        return WorkspaceContext::crossTenant('reason: scheduled maintenance marks ambiguous stale Digital Bill provider attempts terminal without sending', fn (): int => RestaurantDigitalBillDelivery::query()
            ->where('status', RestaurantDigitalBillDelivery::STATUS_SENDING)
            ->whereNotNull('provider_attempt_started_at')
            ->where('provider_attempt_started_at', '<=', $now->copy()->subMinutes($minutes))
            ->update([
                'status' => RestaurantDigitalBillDelivery::STATUS_OUTCOME_UNKNOWN,
                'reason_code' => self::REASON_PROVIDER_OUTCOME_UNKNOWN,
                'failed_at' => $now,
                'updated_at' => $now,
            ]));
    }

    /** @return array{RestaurantOutboundDecision, RestaurantDigitalBillDeliveryConfig|null} */
    private function eligibility(RestaurantBill $bill, int $workspaceId): array
    {
        $config = $bill->outlet_id === null ? null : RestaurantDigitalBillDeliveryConfig::query()->where('outlet_id', $bill->outlet_id)->first();
        if ($config === null) {
            return [RestaurantOutboundDecision::block(RestaurantOutboundPurpose::DIGITAL_BILL, self::REASON_CONFIG_MISSING, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id), null];
        }
        if ($config->whatsapp_phone_number_id === null || $config->whatsapp_template_id === null) {
            return [RestaurantOutboundDecision::block(RestaurantOutboundPurpose::DIGITAL_BILL, self::REASON_CONFIG_INCOMPLETE, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id), $config];
        }
        $sender = WhatsappPhoneNumber::query()->find($config->whatsapp_phone_number_id);
        if ($sender === null) {
            return [RestaurantOutboundDecision::block(RestaurantOutboundPurpose::DIGITAL_BILL, self::REASON_CONFIG_INCOMPLETE, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id), $config];
        }
        $decision = $this->policy->evaluate(RestaurantOutboundPurpose::DIGITAL_BILL, $bill->id, $sender->phone_number_id, $config->whatsapp_template_id);
        if (! $decision->allowed) {
            return [$decision, $config];
        }
        $template = WhatsappTemplate::query()->find($config->whatsapp_template_id);
        if ($template === null) {
            return [RestaurantOutboundDecision::block(RestaurantOutboundPurpose::DIGITAL_BILL, self::REASON_CONFIG_INCOMPLETE, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id), $config];
        }
        $compiled = $this->compiler->compile($template, $bill, $workspaceId);

        return [$compiled instanceof RestaurantOutboundDecision ? $compiled : $decision, $config];
    }

    private function suppress(RestaurantDigitalBillDelivery $delivery, string $reason): void
    {
        $delivery->forceFill(['status' => RestaurantDigitalBillDelivery::STATUS_SUPPRESSED, 'reason_code' => $reason, 'suppressed_at' => now()])->save();
        $this->auditSuppressed($delivery);
    }

    private function outcomeUnknown(RestaurantDigitalBillDelivery $delivery, string $reason): void
    {
        $delivery->forceFill(['status' => RestaurantDigitalBillDelivery::STATUS_OUTCOME_UNKNOWN, 'reason_code' => $reason, 'failed_at' => now()])->save();
        $this->audit->logSystem('restaurant.digital_bill.outcome_unknown', $delivery, $delivery->workspace_id, [
            'delivery_id' => $delivery->id,
            'restaurant_bill_id' => $delivery->restaurant_bill_id,
            'outlet_id' => $delivery->outlet_id,
            'reason_code' => $reason,
        ]);
    }

    private function failBeforeProvider(RestaurantDigitalBillDelivery $delivery): void
    {
        $delivery->forceFill([
            'status' => RestaurantDigitalBillDelivery::STATUS_FAILED,
            'reason_code' => self::REASON_PRE_SEND_EVALUATION_FAILED,
            'failed_at' => now(),
        ])->save();
        $this->audit->logSystem('restaurant.digital_bill.failed', $delivery, $delivery->workspace_id, [
            'delivery_id' => $delivery->id,
            'restaurant_bill_id' => $delivery->restaurant_bill_id,
            'outlet_id' => $delivery->outlet_id,
            'reason_code' => self::REASON_PRE_SEND_EVALUATION_FAILED,
        ]);
    }

    private function auditSuppressed(RestaurantDigitalBillDelivery $delivery): void
    {
        $this->audit->logSystem('restaurant.digital_bill.suppressed', $delivery, $delivery->workspace_id, [
            'delivery_id' => $delivery->id,
            'restaurant_bill_id' => $delivery->restaurant_bill_id,
            'outlet_id' => $delivery->outlet_id,
            'reason_code' => $delivery->reason_code,
        ]);
    }
}
