<?php

namespace App\Modules\Flows\Services;

use App\Modules\Flows\Models\WhatsappFlowKeyPair;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Services\CloudApiClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Generates, versions, and uploads endpoint-encryption keys without handling HTTP directly. */
class WhatsappFlowKeyPairService
{
    public const MISSING_MESSAGING_PERMISSION_MESSAGE = 'Your WhatsApp connection needs additional permissions to encrypt Flow data. Please reconnect your WhatsApp Business Account.';

    public function __construct(private readonly RsaKeyPairGenerator $generator) {}

    public function generate(WhatsappPhoneNumber $phoneNumber, int $workspaceId): WhatsappFlowKeyPair
    {
        return DB::transaction(function () use ($phoneNumber, $workspaceId): WhatsappFlowKeyPair {
            $nextVersion = ((int) WhatsappFlowKeyPair::query()
                ->where('workspace_id', $workspaceId)
                ->where('whatsapp_phone_number_id', $phoneNumber->id)
                ->lockForUpdate()
                ->max('key_version')) + 1;
            $pem = $this->generator->generate();

            return WhatsappFlowKeyPair::create([
                'workspace_id' => $workspaceId,
                'whatsapp_phone_number_id' => $phoneNumber->id,
                'public_key_pem' => $pem['public_key_pem'],
                'private_key_pem' => $pem['private_key_pem'],
                'key_version' => $nextVersion,
                'meta_upload_status' => WhatsappFlowKeyPair::UPLOAD_NOT_UPLOADED,
                'status' => WhatsappFlowKeyPair::STATUS_ACTIVE,
            ]);
        });
    }

    /** @return array{success:bool,message:string} */
    public function upload(WhatsappFlowKeyPair $keyPair): array
    {
        $phoneNumber = $keyPair->phoneNumber;
        if (! $phoneNumber) {
            return $this->fail($keyPair, 'This encryption key is no longer linked to a WhatsApp phone number.');
        }

        $client = CloudApiClient::forPhoneNumber($phoneNumber->phone_number_id, $keyPair->workspace_id);
        if (! $client) {
            return $this->fail($keyPair, 'Connect an active WhatsApp phone number before uploading its encryption key.');
        }

        try {
            $response = $client->uploadEncryptionKey($phoneNumber->phone_number_id, $keyPair->public_key_pem);
        } catch (Throwable) {
            return $this->fail($keyPair, 'Meta could not upload this encryption key. Please try again.');
        }

        if ($response->successful() && $response->json('success', false)) {
            $keyPair->update([
                'meta_upload_status' => WhatsappFlowKeyPair::UPLOAD_UPLOADED,
                'meta_uploaded_at' => now(),
                'meta_upload_error' => null,
            ]);

            return ['success' => true, 'message' => 'Encryption key uploaded to Meta.'];
        }

        return $this->failFromResponse($keyPair, $response);
    }

    /** @return array{success:bool,message:string,key_pair:WhatsappFlowKeyPair} */
    public function rotate(WhatsappPhoneNumber $phoneNumber, int $workspaceId): array
    {
        $oldActiveKeys = WhatsappFlowKeyPair::query()
            ->where('workspace_id', $workspaceId)
            ->where('whatsapp_phone_number_id', $phoneNumber->id)
            ->where('status', WhatsappFlowKeyPair::STATUS_ACTIVE)
            ->get();
        $newKey = $this->generate($phoneNumber, $workspaceId);
        $result = $this->upload($newKey);

        if (! $result['success']) {
            $newKey->update(['status' => WhatsappFlowKeyPair::STATUS_REVOKED]);

            return ['success' => false, 'message' => $result['message'], 'key_pair' => $newKey->fresh()];
        }

        foreach ($oldActiveKeys as $oldKey) {
            $oldKey->update(['status' => WhatsappFlowKeyPair::STATUS_ROTATED, 'rotated_at' => now()]);
        }

        return ['success' => true, 'message' => 'Encryption key rotated and uploaded to Meta.', 'key_pair' => $newKey->fresh()];
    }

    /** @return array{success:bool,message:string} */
    private function failFromResponse(WhatsappFlowKeyPair $keyPair, Response $response): array
    {
        if (WhatsappFlowMetaSyncService::isPermissionError($response)) {
            return $this->fail($keyPair, self::MISSING_MESSAGING_PERMISSION_MESSAGE);
        }

        $message = $response->json('error.message');
        $reason = is_string($message) && $message !== ''
            ? 'Meta rejected this encryption key: '.str($message)->limit(400, '')
            : 'Meta could not upload this encryption key. Please try again.';

        return $this->fail($keyPair, $reason);
    }

    /** @return array{success:bool,message:string} */
    private function fail(WhatsappFlowKeyPair $keyPair, string $message): array
    {
        $keyPair->update([
            'meta_upload_status' => WhatsappFlowKeyPair::UPLOAD_FAILED,
            'meta_upload_error' => $message,
        ]);

        return ['success' => false, 'message' => $message];
    }
}
