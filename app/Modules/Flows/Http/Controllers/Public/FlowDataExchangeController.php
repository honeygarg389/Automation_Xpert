<?php

namespace App\Modules\Flows\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Flows\Models\WhatsappFlowKeyPair;
use App\Modules\Flows\Services\DecryptedFlowRequest;
use App\Modules\Flows\Services\FlowDecryptionException;
use App\Modules\Flows\Services\FlowEncryptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST /webhooks/flows/{token} — Meta's encrypted Flow data-exchange endpoint.
 *
 * The token is only an opaque routing capability. The encrypted envelope and
 * AES-GCM tag authenticate the request; no Laravel user/workspace context may
 * be assumed before the one active key pair is resolved.
 */
class FlowDataExchangeController extends Controller
{
    public function __invoke(Request $request, string $token, FlowEncryptionService $encryption): Response
    {
        $keyPair = WhatsappFlowKeyPair::findActiveByEndpointToken($token);
        if (! $keyPair) {
            // Do not distinguish unknown from rotated/revoked keys.
            return response('', 404);
        }

        try {
            $decrypted = $encryption->decrypt($request->all(), $keyPair->private_key_pem);
            $response = $this->responseFor($decrypted);

            return response($encryption->encryptResponse($response, $decrypted), 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        } catch (FlowDecryptionException) {
            // Meta's endpoint sample specifies 421 so the client refreshes its
            // public key when the private/public pair no longer agrees.
            return response('', 421);
        } catch (Throwable $e) {
            Log::error('WhatsApp Flow data exchange failed.', [
                'key_pair_id' => $keyPair->id,
                'workspace_id' => $keyPair->workspace_id,
                'error' => $e->getMessage(),
            ]);

            return response('', 500);
        }
    }

    /** @return array<string, mixed> */
    private function responseFor(DecryptedFlowRequest $request): array
    {
        $action = $request->body['action'] ?? null;
        if ($action === 'ping') {
            // Meta's endpoint health check expects this exact decrypted shape.
            return ['data' => ['status' => 'active']];
        }

        // Slice 4 deliberately has no per-Flow dynamic business engine yet.
        // A same-screen acknowledgement proves the encrypted request/response
        // contract without echoing any submitted user data or inventing a Flow
        // definition language before the dynamic-builder slice.
        $screen = $request->body['screen'] ?? null;
        if (is_string($screen) && $screen !== '') {
            return ['screen' => $screen, 'data' => ['acknowledged' => true]];
        }

        throw new FlowDecryptionException('Unsupported Flow endpoint action.');
    }
}
