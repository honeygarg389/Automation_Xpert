<?php

namespace App\Modules\Flows\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Modules\Flows\Models\WhatsappFlowKeyPair;
use App\Modules\Flows\Services\WhatsappFlowKeyPairService;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Client settings for per-phone-number WhatsApp Flow endpoint encryption. */
class WhatsappFlowKeyPairController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaceId = $this->workspaceId($request);
        $pairs = WhatsappFlowKeyPair::query()->get()->keyBy('whatsapp_phone_number_id');
        $phones = WhatsappPhoneNumber::query()
            ->whereHas('businessAccount', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->with('businessAccount:id,waba_id')
            ->orderBy('id')
            ->get()
            ->map(fn (WhatsappPhoneNumber $phone) => [
                'id' => $phone->id,
                'phone_number_id' => $phone->phone_number_id,
                'display_phone' => $phone->display_phone,
                'verified_name' => $phone->verified_name,
                'waba_id' => $phone->businessAccount?->waba_id,
                'key_pair' => ($pair = $pairs->get($phone->id)) ? $this->summary($pair) : null,
            ])->values();

        return Inertia::render('client/Flows/EncryptionKeys', ['phones' => $phones]);
    }

    public function generate(Request $request, WhatsappPhoneNumber $phoneNumber, WhatsappFlowKeyPairService $keys): RedirectResponse
    {
        $keyPair = $keys->generate($this->ownedPhone($request, $phoneNumber), $this->workspaceId($request));

        return back()->with('success', "Encryption key version {$keyPair->key_version} generated. Upload it to Meta before enabling a data endpoint.");
    }

    public function upload(Request $request, WhatsappPhoneNumber $phoneNumber, WhatsappFlowKeyPairService $keys): RedirectResponse
    {
        $phone = $this->ownedPhone($request, $phoneNumber);
        $keyPair = WhatsappFlowKeyPair::query()
            ->where('whatsapp_phone_number_id', $phone->id)
            ->where('status', WhatsappFlowKeyPair::STATUS_ACTIVE)
            ->latest('key_version')
            ->firstOrFail();
        $result = $keys->upload($keyPair);

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    public function rotate(Request $request, WhatsappPhoneNumber $phoneNumber, WhatsappFlowKeyPairService $keys): RedirectResponse
    {
        $phone = $this->ownedPhone($request, $phoneNumber);
        if (! WhatsappFlowKeyPair::query()->where('whatsapp_phone_number_id', $phone->id)->exists()) {
            return back()->with('error', 'Generate an encryption key before rotating it.');
        }
        $result = $keys->rotate($phone, $this->workspaceId($request));

        return back()->with($result['success'] ? 'success' : 'error', $result['message']);
    }

    private function ownedPhone(Request $request, WhatsappPhoneNumber $phoneNumber): WhatsappPhoneNumber
    {
        $workspaceId = $this->workspaceId($request);
        abort_unless($phoneNumber->businessAccount()->where('workspace_id', $workspaceId)->exists(), 404);

        return $phoneNumber;
    }

    private function workspaceId(Request $request): int
    {
        return (int) (WorkspaceContext::id() ?? $request->user()->workspace_id);
    }

    /** @return array<string,mixed> */
    private function summary(WhatsappFlowKeyPair $keyPair): array
    {
        return [
            'uuid' => $keyPair->uuid,
            'key_version' => $keyPair->key_version,
            'status' => $keyPair->status,
            'meta_upload_status' => $keyPair->meta_upload_status,
            'meta_uploaded_at' => $keyPair->meta_uploaded_at?->toISOString(),
            'meta_upload_error' => $keyPair->meta_upload_error,
        ];
    }
}
