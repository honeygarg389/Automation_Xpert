<?php

namespace App\Modules\Flows\Services;

use RuntimeException;

/** Pure native-OpenSSL generator for Meta Flow endpoint-encryption key pairs. */
class RsaKeyPairGenerator
{
    /** @return array{public_key_pem:string,private_key_pem:string} */
    public function generate(): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            throw new RuntimeException('OpenSSL could not generate an RSA key pair.');
        }

        $privateKeyPem = '';
        if (! openssl_pkey_export($key, $privateKeyPem) || $privateKeyPem === '') {
            throw new RuntimeException('OpenSSL could not export the RSA private key.');
        }

        $details = openssl_pkey_get_details($key);
        $publicKeyPem = is_array($details) ? (string) ($details['key'] ?? '') : '';
        if ($publicKeyPem === '') {
            throw new RuntimeException('OpenSSL could not read the RSA public key.');
        }

        return ['public_key_pem' => $publicKeyPem, 'private_key_pem' => $privateKeyPem];
    }
}
