<?php

namespace App\Modules\Flows\Services;

use JsonException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use Throwable;

/**
 * Meta WhatsApp Flow data-exchange envelope codec.
 *
 * Meta wraps a 128-bit AES key with RSA-OAEP SHA-256/MGF1 SHA-256, then appends
 * the 16-byte AES-128-GCM authentication tag to the ciphertext. PHP 8.2's
 * native OpenSSL wrapper cannot select OAEP SHA-256, so phpseclib provides the
 * portable RSA operation while OpenSSL provides authenticated AES-GCM.
 */
class FlowEncryptionService
{
    private const AES_KEY_BYTES = 16;

    private const GCM_TAG_BYTES = 16;

    private const IV_BYTES = 16;

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws FlowDecryptionException
     */
    public function decrypt(array $payload, string $privateKeyPem): DecryptedFlowRequest
    {
        try {
            $encryptedAesKey = $this->base64Field($payload, 'encrypted_aes_key');
            $encryptedFlowData = $this->base64Field($payload, 'encrypted_flow_data');
            $initialVector = $this->base64Field($payload, 'initial_vector');

            if (strlen($initialVector) !== self::IV_BYTES) {
                throw new FlowDecryptionException('Invalid Flow initial vector length.');
            }
            if (strlen($encryptedFlowData) <= self::GCM_TAG_BYTES) {
                throw new FlowDecryptionException('Flow data is missing its authentication tag.');
            }

            /** @var PrivateKey $privateKey */
            $privateKey = PublicKeyLoader::loadPrivateKey($privateKeyPem)
                ->withPadding(RSA::ENCRYPTION_OAEP)
                ->withHash('sha256')
                ->withMGFHash('sha256');
            $aesKey = $privateKey->decrypt($encryptedAesKey);

            if (strlen($aesKey) !== self::AES_KEY_BYTES) {
                throw new FlowDecryptionException('Invalid Flow AES key length.');
            }

            $ciphertext = substr($encryptedFlowData, 0, -self::GCM_TAG_BYTES);
            $tag = substr($encryptedFlowData, -self::GCM_TAG_BYTES);
            $plaintext = openssl_decrypt(
                $ciphertext,
                'aes-128-gcm',
                $aesKey,
                OPENSSL_RAW_DATA,
                $initialVector,
                $tag,
            );
            if (! is_string($plaintext)) {
                throw new FlowDecryptionException('Flow AES-GCM authentication failed.');
            }

            $body = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($body)) {
                throw new FlowDecryptionException('Flow plaintext must be a JSON object.');
            }

            return new DecryptedFlowRequest($body, $aesKey, $initialVector);
        } catch (FlowDecryptionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new FlowDecryptionException('Unable to decrypt Flow data.', previous: $e);
        }
    }

    /**
     * Encrypts a plain JSON response for Meta. The response body is the bare
     * base64 string of ciphertext followed by its 16-byte GCM tag.
     *
     * @param  array<string, mixed>  $response
     */
    public function encryptResponse(array $response, DecryptedFlowRequest $request): string
    {
        try {
            $flippedVector = $this->flipInitialVector($request->initialVector);
            $tag = '';
            $ciphertext = openssl_encrypt(
                json_encode($response, JSON_THROW_ON_ERROR),
                'aes-128-gcm',
                $request->aesKey,
                OPENSSL_RAW_DATA,
                $flippedVector,
                $tag,
                '',
                self::GCM_TAG_BYTES,
            );
            if (! is_string($ciphertext) || strlen($tag) !== self::GCM_TAG_BYTES) {
                throw new FlowDecryptionException('Unable to encrypt Flow response.');
            }

            return base64_encode($ciphertext.$tag);
        } catch (JsonException $e) {
            throw new FlowDecryptionException('Unable to encode Flow response.', previous: $e);
        }
    }

    /** Meta requires every bit of the request IV to be flipped for responses. */
    public function flipInitialVector(string $initialVector): string
    {
        if (strlen($initialVector) !== self::IV_BYTES) {
            throw new FlowDecryptionException('Invalid Flow initial vector length.');
        }

        return ~$initialVector;
    }

    /** @param array<string, mixed> $payload */
    private function base64Field(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (! is_string($value) || $value === '') {
            throw new FlowDecryptionException("Missing {$field}.");
        }

        $decoded = base64_decode($value, true);
        if (! is_string($decoded) || $decoded === '') {
            throw new FlowDecryptionException("Invalid {$field}.");
        }

        return $decoded;
    }
}
