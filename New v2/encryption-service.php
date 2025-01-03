<?php

namespace App\Core\Security;

class EncryptionService implements EncryptionInterface
{
    private string $key;
    private string $cipher;

    public function __construct(string $key, string $cipher = 'aes-256-gcm')
    {
        $this->key = $key;
        $this->cipher = $cipher;
    }

    public function encrypt(string $data): string
    {
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        return base64_encode($iv . $tag . $encrypted);
    }

    public function decrypt(string $data): string
    {
        $decoded = base64_decode($data);
        
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $iv = substr($decoded, 0, $ivLength);
        $tag = substr($decoded, $ivLength, 16);
        $encrypted = substr($decoded, $ivLength + 16);

        return openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
    }

    public function hash(string $data): string
    {
        return hash_hmac('sha256', $data, $this->key);
    }

    public function verify(string $data, string $hash): bool
    {
        return hash_equals($hash, $this->hash($data));
    }
}
