<?php

namespace App\Modules\Restaurant\Services;

use App\Models\AdminUser;
use App\Models\User;
use App\Modules\Restaurant\Models\RestaurantDigitalBillDeliveryConfig;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Services\AuditLogService;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persists an outlet's local sender/template choices without ever sending.
 *
 * The saved values are deliberately not a readiness promise. A later delivery
 * job must freshly resolve these rows and run RestaurantOutboundPolicy before
 * dispatch and immediately before provider delivery.
 */
final class RestaurantDigitalBillDeliveryConfigService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function save(
        RestaurantOutlet $outlet,
        int $phoneNumberId,
        int $templateId,
        AdminUser|User $actor,
    ): RestaurantDigitalBillDeliveryConfig {
        $workspaceId = (int) $outlet->workspace_id;

        if ($actor instanceof User && WorkspaceContext::id() !== $workspaceId) {
            abort(404);
        }

        $sender = $this->eligibleSender($phoneNumberId, $workspaceId);
        $template = $this->eligibleTemplate($templateId, $workspaceId, $sender->businessAccount->waba_id);

        return DB::transaction(function () use ($outlet, $workspaceId, $phoneNumberId, $templateId, $actor, $sender, $template): RestaurantDigitalBillDeliveryConfig {
            /** @var RestaurantDigitalBillDeliveryConfig $config */
            $config = RestaurantDigitalBillDeliveryConfig::query()
                ->where('workspace_id', $workspaceId)
                ->where('outlet_id', $outlet->id)
                ->lockForUpdate()
                ->first() ?? new RestaurantDigitalBillDeliveryConfig([
                    'workspace_id' => $workspaceId,
                    'outlet_id' => $outlet->id,
                ]);

            $oldValues = [
                'whatsapp_phone_number_id' => $config->whatsapp_phone_number_id,
                'whatsapp_template_id' => $config->whatsapp_template_id,
            ];
            $newValues = [
                'whatsapp_phone_number_id' => $phoneNumberId,
                'whatsapp_template_id' => $templateId,
            ];

            $config->fill($newValues)->save();

            $meta = [
                'workspace_id' => $workspaceId,
                'outlet_id' => $outlet->id,
                // This is only the validation performed at save time, never a
                // cached operational-ready state. It contains no credentials.
                'selection_validated' => true,
                'selected_sender_id' => $sender->id,
                'selected_template_id' => $template->id,
            ];

            if ($actor instanceof AdminUser) {
                $this->auditLog->logAdmin(
                    action: 'restaurant.outlet.digital_bill_delivery_config_updated',
                    targetType: RestaurantDigitalBillDeliveryConfig::class,
                    targetId: $config->id,
                    meta: $meta,
                    admin: $actor,
                    oldValues: $oldValues,
                    newValues: $newValues,
                    workspaceId: $workspaceId,
                );
            } else {
                $this->auditLog->log(
                    action: 'restaurant.outlet.digital_bill_delivery_config_updated',
                    auditable: $config,
                    oldValues: $oldValues,
                    newValues: $newValues,
                    workspaceId: $workspaceId,
                );
            }

            return $config->refresh();
        });
    }

    /**
     * @return array{senders: list<array{id: int, label: string, templates: list<array{id: int, label: string}>}>}
     */
    public function optionsForWorkspace(int $workspaceId): array
    {
        /** @var Collection<int, WhatsappBusinessAccount> $accounts */
        $accounts = WhatsappBusinessAccount::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->with('phoneNumbers')
            ->get()
            ->filter(fn (WhatsappBusinessAccount $account): bool => filled($account->accessToken()));

        $templates = WhatsappTemplate::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'APPROVED')
            ->where('category', 'UTILITY')
            ->orderBy('name')
            ->get()
            ->groupBy('waba_id');

        return [
            'senders' => $accounts->flatMap(function (WhatsappBusinessAccount $account) use ($templates): array {
                /** @var Collection<int, WhatsappTemplate> $accountTemplates */
                $accountTemplates = $templates->get($account->waba_id, collect());

                return $account->phoneNumbers->map(fn (WhatsappPhoneNumber $phone): array => [
                    'id' => $phone->id,
                    'label' => $phone->display_phone ?: $phone->verified_name ?: 'WhatsApp sender #'.$phone->id,
                    'templates' => $accountTemplates->map(fn (WhatsappTemplate $template): array => [
                        'id' => $template->id,
                        'label' => $template->name.' ('.$template->language.')',
                    ])->values()->all(),
                ])->all();
            })->values()->all(),
        ];
    }

    private function eligibleSender(int $phoneNumberId, int $workspaceId): WhatsappPhoneNumber
    {
        /** @var WhatsappPhoneNumber|null $sender */
        $sender = WhatsappPhoneNumber::query()
            ->whereKey($phoneNumberId)
            ->whereHas('businessAccount', fn ($query) => $query
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active'))
            ->with('businessAccount')
            ->first();

        if ($sender === null || $sender->businessAccount === null || ! filled($sender->businessAccount->accessToken())) {
            throw ValidationException::withMessages([
                'whatsapp_phone_number_id' => 'Select an active workspace WhatsApp sender with a usable access token.',
            ]);
        }

        return $sender;
    }

    private function eligibleTemplate(int $templateId, int $workspaceId, string $wabaId): WhatsappTemplate
    {
        /** @var WhatsappTemplate|null $template */
        $template = WhatsappTemplate::query()
            ->whereKey($templateId)
            ->where('workspace_id', $workspaceId)
            ->where('waba_id', $wabaId)
            ->where('status', 'APPROVED')
            ->where('category', 'UTILITY')
            ->first();

        if ($template === null) {
            throw ValidationException::withMessages([
                'whatsapp_template_id' => 'Select an approved Utility template from the selected sender’s WhatsApp Business Account.',
            ]);
        }

        return $template;
    }
}
