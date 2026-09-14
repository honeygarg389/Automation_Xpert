<?php

namespace Tests\Unit\Flows;

use App\Modules\Flows\Services\RsaKeyPairGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RsaKeyPairGeneratorTest extends TestCase
{
    #[Test]
    public function generated_public_and_private_keys_can_encrypt_and_decrypt_a_message(): void
    {
        $pair = app(RsaKeyPairGenerator::class)->generate();
        $plaintext = 'Flow endpoint encryption round trip';
        $ciphertext = '';
        $decrypted = '';

        $this->assertStringContainsString('BEGIN PUBLIC KEY', $pair['public_key_pem']);
        $this->assertTrue(openssl_public_encrypt($plaintext, $ciphertext, $pair['public_key_pem'], OPENSSL_PKCS1_OAEP_PADDING));
        $this->assertTrue(openssl_private_decrypt($ciphertext, $decrypted, $pair['private_key_pem'], OPENSSL_PKCS1_OAEP_PADDING));
        $this->assertSame($plaintext, $decrypted);
    }
}
