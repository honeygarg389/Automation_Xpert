<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Restaurant\Jobs\SendRestaurantFeedbackRequestJob;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantFeedbackDeliveryConfig;
use App\Modules\Restaurant\Models\RestaurantFeedbackRequest;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Support\RestaurantOutboundDecision;
use App\Modules\Restaurant\Support\RestaurantOutboundPurpose;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Services\AuditLogService;
use App\Support\WorkspaceContext;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Creates one durable, fail-closed feedback request for a successfully processed bill. */
final class RestaurantFeedbackDeliveryService
{
    public const REASON_CONFIG_MISSING = 'feedback_delivery_config_missing';

    public const REASON_CONFIG_INCOMPLETE = 'feedback_delivery_config_incomplete';

    public const REASON_OUTLET_TIMEZONE_INVALID = 'outlet_timezone_invalid';

    public function __construct(
        private readonly RestaurantOutboundPolicy $policy,
        private readonly FeedbackRequestTemplatePayloadCompiler $compiler,
        private readonly AuditLogService $audit,
    ) {}

    public function schedule(int $billId): ?RestaurantFeedbackRequest
    {
        $workspaceId = WorkspaceContext::id();
        if ($workspaceId === null) {
            return null;
        }

        /** @var RestaurantBill|null $bill */
        $bill = RestaurantBill::query()->find($billId);
        if ($bill === null) {
            return null;
        }

        try {
            return DB::transaction(function () use ($bill, $workspaceId): RestaurantFeedbackRequest {
                /** @var RestaurantFeedbackRequest|null $existing */
                $existing = RestaurantFeedbackRequest::query()
                    ->where('restaurant_bill_id', $bill->id)
                    ->where('purpose', RestaurantFeedbackRequest::PURPOSE_FEEDBACK_REQUEST)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }

                [$decision, $config, $scheduledFor] = $this->eligibility($bill, $workspaceId);
                $request = new RestaurantFeedbackRequest([
                    'workspace_id' => $workspaceId,
                    'restaurant_bill_id' => $bill->id,
                    'outlet_id' => $bill->outlet_id,
                    'restaurant_feedback_delivery_config_id' => $config?->id,
                    'purpose' => RestaurantFeedbackRequest::PURPOSE_FEEDBACK_REQUEST,
                    'scheduled_for' => $scheduledFor ?? now(),
                ]);
                $request->forceFill(['public_token' => bin2hex(random_bytes(32))]);

                if (! $decision->allowed || $scheduledFor === null) {
                    $request->fill([
                        'status' => RestaurantFeedbackRequest::STATUS_SUPPRESSED,
                        'reason_code' => $decision->reasonCode ?? self::REASON_OUTLET_TIMEZONE_INVALID,
                        'suppressed_at' => now(),
                    ])->save();

                    return $request->refresh();
                }

                $request->fill([
                    'status' => RestaurantFeedbackRequest::STATUS_SCHEDULED,
                    'reason_code' => null,
                ])->save();

                return $request->refresh();
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            return RestaurantFeedbackRequest::query()
                ->where('restaurant_bill_id', $bill->id)
                ->where('purpose', RestaurantFeedbackRequest::PURPOSE_FEEDBACK_REQUEST)
                ->firstOrFail();
        }
    }

    /** Dispatches due ledger rows; it deliberately performs no provider call. */
    public function dispatchDue(): int
    {
        $ids = WorkspaceContext::crossTenant('reason: scheduled feedback sweep must find due rows across workspaces before each job establishes its own trusted request context', fn (): array => RestaurantFeedbackRequest::query()
            ->where('status', RestaurantFeedbackRequest::STATUS_SCHEDULED)
            ->where('scheduled_for', '<=', now())
            ->orderBy('id')
            ->limit(250)
            ->pluck('id')
            ->all());
        $count = 0;
        foreach ($ids as $id) {
            $claimed = WorkspaceContext::crossTenant('reason: scheduled feedback sweep atomically claims one due ledger row before dispatching its tenant-scoped job', fn (): int => RestaurantFeedbackRequest::query()
                ->whereKey($id)
                ->where('status', RestaurantFeedbackRequest::STATUS_SCHEDULED)
                ->where('scheduled_for', '<=', now())
                ->update(['status' => RestaurantFeedbackRequest::STATUS_PENDING, 'updated_at' => now()]));
            if ($claimed === 1) {
                SendRestaurantFeedbackRequestJob::dispatch((int) $id)->onQueue('restaurant');
                $count++;
            }
        }

        return $count;
    }

    public function send(int $requestId): void
    {
        $workspaceId = WorkspaceContext::id();
        if ($workspaceId === null) {
            return;
        }
        /** @var RestaurantFeedbackRequest|null $request */
        $request = RestaurantFeedbackRequest::query()->find($requestId);
        if ($request === null || $request->status !== RestaurantFeedbackRequest::STATUS_PENDING) {
            return;
        }
        if (RestaurantFeedbackRequest::query()->whereKey($request->id)->where('status', RestaurantFeedbackRequest::STATUS_PENDING)->update(['status' => RestaurantFeedbackRequest::STATUS_SENDING, 'updated_at' => now()]) !== 1) {
            return;
        }

        try {
            /** @var RestaurantBill|null $bill */
            $bill = RestaurantBill::query()->find($request->restaurant_bill_id);
            if ($bill === null) {
                $this->suppress($request, 'bill_missing');

                return;
            }
            [$decision, $config] = $this->currentEligibility($bill, $workspaceId);
            if (! $decision->allowed || $config === null) {
                $this->suppress($request, $decision->reasonCode ?? self::REASON_CONFIG_MISSING);

                return;
            }
            /** @var WhatsappTemplate|null $template */
            $template = WhatsappTemplate::query()->find($config->whatsapp_template_id);
            /** @var WhatsappPhoneNumber|null $sender */
            $sender = WhatsappPhoneNumber::query()->find($config->whatsapp_phone_number_id);
            if ($template === null || $sender === null) {
                $this->suppress($request, self::REASON_CONFIG_INCOMPLETE);

                return;
            }
            $components = $this->compiler->compile($template, $request);
            if ($components instanceof RestaurantOutboundDecision) {
                $this->suppress($request, $components->reasonCode ?? self::REASON_CONFIG_INCOMPLETE);

                return;
            }
            $contact = $bill->contact;
            $account = $sender->businessAccount;
            if ($contact === null || ! is_string($contact->phone_e164) || $account === null || ! filled($account->accessToken())) {
                $this->suppress($request, RestaurantOutboundPolicy::REASON_WHATSAPP_SENDER_NOT_READY);

                return;
            }
        } catch (Throwable) {
            $this->failBeforeProvider($request);

            return;
        }

        $request->forceFill(['provider_attempt_started_at' => now(), 'attempt_count' => $request->attempt_count + 1, 'updated_at' => now()])->save();
        try {
            $response = (new CloudApiClient($sender->phone_number_id, $account->accessToken()))->sendTemplate($contact->phone_e164, $template->name, $template->language, $components);
            $messageId = $response->successful() ? data_get($response->json(), 'messages.0.id') : null;
            if (! is_string($messageId) || $messageId === '') {
                $this->outcomeUnknown($request, 'provider_response_invalid');

                return;
            }
            $request->forceFill(['status' => RestaurantFeedbackRequest::STATUS_SENT, 'provider_message_id' => $messageId, 'sent_at' => now(), 'reason_code' => null])->save();
            $this->audit->logSystem('restaurant.feedback_request.sent', $request, $workspaceId, ['feedback_request_id' => $request->id, 'outlet_id' => $request->outlet_id]);
        } catch (Throwable) {
            $this->outcomeUnknown($request, 'provider_outcome_unknown');
        }
    }

    public function markStalledAttemptsOutcomeUnknown(int $minutes): int
    {
        return WorkspaceContext::crossTenant('reason: scheduled feedback maintenance terminalizes stale provider-boundary attempts and never resends them', fn (): int => RestaurantFeedbackRequest::query()
            ->where('status', RestaurantFeedbackRequest::STATUS_SENDING)
            ->whereNotNull('provider_attempt_started_at')
            ->where('provider_attempt_started_at', '<=', now()->subMinutes(max(1, $minutes)))
            ->update(['status' => RestaurantFeedbackRequest::STATUS_OUTCOME_UNKNOWN, 'reason_code' => 'provider_outcome_unknown', 'failed_at' => now(), 'updated_at' => now()]));
    }

    /** @return array{RestaurantOutboundDecision, RestaurantFeedbackDeliveryConfig|null, Carbon|null} */
    private function eligibility(RestaurantBill $bill, int $workspaceId): array
    {
        $config = $bill->outlet_id === null
            ? null
            : RestaurantFeedbackDeliveryConfig::query()->where('outlet_id', $bill->outlet_id)->first();
        if ($config === null) {
            return [RestaurantOutboundDecision::block(RestaurantOutboundPurpose::FEEDBACK_REQUEST, self::REASON_CONFIG_MISSING, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id), null, null];
        }
        if ($config->whatsapp_phone_number_id === null || $config->whatsapp_template_id === null) {
            return [RestaurantOutboundDecision::block(RestaurantOutboundPurpose::FEEDBACK_REQUEST, self::REASON_CONFIG_INCOMPLETE, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id), $config, null];
        }
        /** @var WhatsappPhoneNumber|null $sender */
        $sender = WhatsappPhoneNumber::query()->find($config->whatsapp_phone_number_id);
        if ($sender === null) {
            return [RestaurantOutboundDecision::block(RestaurantOutboundPurpose::FEEDBACK_REQUEST, self::REASON_CONFIG_INCOMPLETE, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id), $config, null];
        }
        $decision = $this->policy->evaluate(RestaurantOutboundPurpose::FEEDBACK_REQUEST, $bill->id, $sender->phone_number_id, $config->whatsapp_template_id);
        if (! $decision->allowed) {
            return [$decision, $config, null];
        }
        $outletId = $bill->getAttribute('outlet_id');
        $outlet = $outletId === null
            ? null
            : RestaurantOutlet::query()->whereKey($outletId)->first();
        $scheduledFor = $outlet === null ? null : $this->scheduledFor($bill, $outlet, $config);
        if ($scheduledFor === null) {
            return [RestaurantOutboundDecision::block(RestaurantOutboundPurpose::FEEDBACK_REQUEST, self::REASON_OUTLET_TIMEZONE_INVALID, $workspaceId, $bill->id, $bill->outlet_id, $bill->contact_id), $config, null];
        }

        return [$decision, $config, $scheduledFor];
    }

    /** @return array{RestaurantOutboundDecision, RestaurantFeedbackDeliveryConfig|null} */
    private function currentEligibility(RestaurantBill $bill, int $workspaceId): array
    {
        [$decision, $config] = $this->eligibility($bill, $workspaceId);

        return [$decision, $config];
    }

    private function suppress(RestaurantFeedbackRequest $request, string $reason): void
    {
        $request->forceFill(['status' => RestaurantFeedbackRequest::STATUS_SUPPRESSED, 'reason_code' => $reason, 'suppressed_at' => now()])->save();
        $this->audit->logSystem('restaurant.feedback_request.suppressed', $request, $request->workspace_id, ['feedback_request_id' => $request->id, 'outlet_id' => $request->outlet_id, 'reason_code' => $reason]);
    }

    private function outcomeUnknown(RestaurantFeedbackRequest $request, string $reason): void
    {
        $request->forceFill(['status' => RestaurantFeedbackRequest::STATUS_OUTCOME_UNKNOWN, 'reason_code' => $reason, 'failed_at' => now()])->save();
        $this->audit->logSystem('restaurant.feedback_request.outcome_unknown', $request, $request->workspace_id, ['feedback_request_id' => $request->id, 'outlet_id' => $request->outlet_id, 'reason_code' => $reason]);
    }

    private function failBeforeProvider(RestaurantFeedbackRequest $request): void
    {
        $request->forceFill(['status' => RestaurantFeedbackRequest::STATUS_FAILED, 'reason_code' => 'pre_send_evaluation_failed', 'failed_at' => now()])->save();
    }

    private function scheduledFor(RestaurantBill $bill, RestaurantOutlet $outlet, RestaurantFeedbackDeliveryConfig $config): ?Carbon
    {
        /** @var Carbon $base */
        $base = ($bill->placed_at ?? $bill->received_at ?? now())->copy();

        return match ($config->timing_preference) {
            RestaurantFeedbackDeliveryConfig::TIMING_IMMEDIATELY => now(),
            RestaurantFeedbackDeliveryConfig::TIMING_ONE_HOUR => $base->addHour(),
            RestaurantFeedbackDeliveryConfig::TIMING_FIVE_HOURS => $base->addHours(5),
            RestaurantFeedbackDeliveryConfig::TIMING_SEVEN_DAYS => $base->addDays(7),
            RestaurantFeedbackDeliveryConfig::TIMING_NEXT_DAY => $this->nextDayAt($base, $outlet->timezone, $config->next_day_at?->format('H:i')),
            default => null,
        };
    }

    private function nextDayAt(Carbon $base, ?string $timezone, ?string $time): ?Carbon
    {
        if (! is_string($timezone) || ! is_string($time) || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            return null;
        }
        try {
            $zone = new DateTimeZone($timezone);
        } catch (Throwable) {
            return null;
        }

        return $base->copy()->setTimezone($zone)->addDay()->setTimeFromTimeString($time)->utc();
    }
}
