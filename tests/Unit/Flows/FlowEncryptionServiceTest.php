<?php

namespace Tests\Unit\Flows;

use App\Modules\Flows\Services\FlowEncryptionService;
use App\Modules\Flows\Services\RsaKeyPairGenerator;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PublicKey;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FlowEncryptionServiceTest extends TestCase
{
    #[Test]
    public function it_decrypts_a_meta_shaped_aes_128_gcm_request_and_encrypts_the_iv_flipped_response(): void
    {
        $keys = app(RsaKeyPairGenerator::class)->generate();
        $service = app(FlowEncryptionService::class);
        $requestBody = [
            'version' => '3.0',
            'action' => 'data_exchange',
            'screen' => 'PROFILE',
            'data' => ['email' => 'ada@example.test'],
            'flow_token' => 'flow-token-123',
        ];
        $aesKey = random_bytes(16);
        $initialVector = random_bytes(16);
        $tag = '';
        $ciphertext = openssl_encrypt(
            json_encode($requestBody, JSON_THROW_ON_ERROR),
            'aes-128-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $initialVector,
            $tag,
        );
        $this->assertIsString($ciphertext);

        /** @var PublicKey $publicKey */
        $publicKey = PublicKeyLoader::loadPublicKey($keys['public_key_pem'])
            ->withPadding(RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256');
        $decrypted = $service->decrypt([
            'encrypted_aes_key' => base64_encode($publicKey->encrypt($aesKey)),
            'encrypted_flow_data' => base64_encode($ciphertext.$tag),
            'initial_vector' => base64_encode($initialVector),
        ], $keys['private_key_pem']);

        $this->assertSame($requestBody, $decrypted->body);
        $this->assertSame($aesKey, $decrypted->aesKey);
        $this->assertSame($initialVector, $decrypted->initialVector);

        $response = ['screen' => 'PROFILE', 'data' => ['acknowledged' => true]];
        $encryptedResponse = base64_decode($service->encryptResponse($response, $decrypted), true);
        $this->assertIsString($encryptedResponse);
        $responseTag = substr($encryptedResponse, -16);
        $responseCiphertext = substr($encryptedResponse, 0, -16);
        $flippedVector = $this->manuallyFlipVector($initialVector);
        $plaintext = openssl_decrypt(
            $responseCiphertext,
            'aes-128-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $flippedVector,
            $responseTag,
        );

        $this->assertSame($response, json_decode((string) $plaintext, true, 512, JSON_THROW_ON_ERROR));
        foreach (range(0, 15) as $offset) {
            $this->assertSame(255, ord($initialVector[$offset]) + ord($flippedVector[$offset]));
        }
    }

    #[Test]
    public function it_flips_every_raw_iv_byte_without_truncating_zero_or_ff_bytes(): void
    {
        $service = app(FlowEncryptionService::class);
        $initialVector = hex2bin('00ff102030405060708090a0b0c0d0e0');
        $expected = hex2bin('ff00efdfcfbfaf9f8f7f6f5f4f3f2f1f');

        $this->assertIsString($initialVector);
        $this->assertIsString($expected);
        $this->assertSame(16, strlen($initialVector));
        $this->assertSame($expected, $this->manuallyFlipVector($initialVector));

        $flipped = $service->flipInitialVector($initialVector);

        $this->assertSame(16, strlen($flipped));
        $this->assertSame($expected, $flipped);
        $this->assertSame('ff00efdfcfbfaf9f8f7f6f5f4f3f2f1f', bin2hex($flipped));
    }

    /** A deliberately non-bitwise, independent implementation of Meta's IV flip. */
    private function manuallyFlipVector(string $initialVector): string
    {
        $flipped = '';
        foreach (str_split($initialVector) as $byte) {
            $flipped .= chr(255 - ord($byte));
        }

        return $flipped;
    }
}
