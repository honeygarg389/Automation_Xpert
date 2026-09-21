<?php

namespace App\Modules\Restaurant\Services;

use App\Models\AdminUser;
use App\Models\User;
use App\Modules\Restaurant\Models\RestaurantFeedbackDeliveryConfig;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Services\AuditLogService;
use App\Support\WorkspaceContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Persists deliberate outlet selections only; never schedules or sends feedback. */
final class RestaurantFeedbackDeliveryConfigService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /** @param array{whatsapp_phone_number_id:int,whatsapp_template_id:int,timing_preference:string,next_day_at:?string,google_review_url:?string} $data */
    public function save(RestaurantOutlet $outlet, array $data, AdminUser|User $actor): RestaurantFeedbackDeliveryConfig
    {
        $workspaceId = (int) $outlet->workspace_id;
        if ($actor instanceof User && WorkspaceContext::id() !== $workspaceId) {
            abort(404);
        }

        $sender = $this->eligibleSender($data['whatsapp_phone_number_id'], $workspaceId);
        $template = $this->eligibleTemplate($data['whatsapp_template_id'], $workspaceId, $sender->businessAccount->waba_id);

        return DB::transaction(function () use ($outlet, $workspaceId, $data, $sender, $template, $actor): RestaurantFeedbackDeliveryConfig {
            /** @var RestaurantFeedbackDeliveryConfig $config */
            $config = RestaurantFeedbackDeliveryConfig::query()->where('outlet_id', $outlet->id)->lockForUpdate()->first()
                ?? new RestaurantFeedbackDeliveryConfig(['workspace_id' => $workspaceId, 'outlet_id' => $outlet->id]);
            $old = $this->auditable($config);
            $config->fill($data)->save();
            $new = $this->auditable($config);
            $meta = ['workspace_id' => $workspaceId, 'outlet_id' => $outlet->id, 'selected_sender_id' => $sender->id, 'selected_template_id' => $template->id, 'selection_validated' => true];

            if ($actor instanceof AdminUser) {
                $this->audit->logAdmin('restaurant.outlet.feedback_delivery_config_updated', RestaurantFeedbackDeliveryConfig::class, $config->id, $meta, $actor, oldValues: $old, newValues: $new, workspaceId: $workspaceId);
            } else {
                $this->audit->log('restaurant.outlet.feedback_delivery_config_updated', $config, $old, $new, workspaceId: $workspaceId);
            }

            return $config->refresh();
        });
    }

    /** @return array{senders:list<array{id:int,label:string,templates:list<array{id:int,label:string}>}>} */
    public function optionsForWorkspace(int $workspaceId): array
    {
        $accounts = WhatsappBusinessAccount::query()->where('workspace_id', $workspaceId)->where('status', 'active')->with('phoneNumbers')->get()
            ->filter(fn ($account): bool => filled($account->accessToken()));
        /** @var Collection<int, WhatsappTemplate> $templates */
        $templates = WhatsappTemplate::query()->where('workspace_id', $workspaceId)->where('status', 'APPROVED')->orderBy('name')->get()->groupBy('waba_id');

        return ['senders' => $accounts->flatMap(function ($account) use ($templates): array {
            /** @var Collection<int, WhatsappTemplate> $accountTemplates */
            $accountTemplates = $templates->get($account->waba_id, collect());

            return $account->phoneNumbers->map(fn (WhatsappPhoneNumber $phone): array => [
                'id' => $phone->id,
                'label' => $phone->display_phone ?: $phone->verified_name ?: 'WhatsApp sender #'.$phone->id,
                'templates' => $accountTemplates->map(fn (WhatsappTemplate $template): array => ['id' => $template->id, 'label' => $template->name.' ('.$template->language.')'])->values()->all(),
            ])->all();
        })->values()->all()];
    }

    private function eligibleSender(int $id, int $workspaceId): WhatsappPhoneNumber
    {
        /** @var WhatsappPhoneNumber|null $sender */
        $sender = WhatsappPhoneNumber::query()->whereKey($id)->whereHas('businessAccount', fn ($query) => $query->where('workspace_id', $workspaceId)->where('status', 'active'))->with('businessAccount')->first();
        if ($sender === null || $sender->businessAccount === null || ! filled($sender->businessAccount->accessToken())) {
            throw ValidationException::withMessages(['whatsapp_phone_number_id' => 'Select an active workspace WhatsApp sender with a usable access token.']);
        }

        return $sender;
    }

    private function eligibleTemplate(int $id, int $workspaceId, string $wabaId): WhatsappTemplate
    {
        /** @var WhatsappTemplate|null $template */
        $template = WhatsappTemplate::query()->whereKey($id)->where('workspace_id', $workspaceId)->where('waba_id', $wabaId)->where('status', 'APPROVED')->first();
        if ($template === null) {
            throw ValidationException::withMessages(['whatsapp_template_id' => 'Select an approved template from the selected sender’s WhatsApp Business Account.']);
        }

        return $template;
    }

    /** @return array{whatsapp_phone_number_id:int|null,whatsapp_template_id:int|null,timing_preference:string|null,next_day_at:string|null,google_review_url:string|null} */
    private function auditable(RestaurantFeedbackDeliveryConfig $config): array
    {
        return ['whatsapp_phone_number_id' => $config->whatsapp_phone_number_id, 'whatsapp_template_id' => $config->whatsapp_template_id, 'timing_preference' => $config->timing_preference, 'next_day_at' => $config->next_day_at?->format('H:i'), 'google_review_url' => $config->google_review_url];
    }
}
