<?php

namespace App\Core\Security;

use App\Core\Interfaces\EncryptionServiceInterface;

class EncryptionService implements EncryptionServiceInterface
{
    private string $key;
    private string $cipher;
    private array $options;
    private KeyRotationManager $keyManager;

    public function __construct(
        KeyRotationManager $keyManager,
        array $options = []
    ) {
        $this->keyManager = $keyManager;
        $this->options = $options;
        $this->initializeEncryption();
    }

    public function encrypt(string $data): string
    {
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($encrypted === false) {
            throw new EncryptionException('Encryption failed');
        }

        $mac = $this->calculateMac($encrypted, $iv);

        return base64_encode(
            json_encode([
                'iv' => base64_encode($iv),
                'value' => base64_encode($encrypted),
                'mac' => $mac,
            ])
        );
    }

    public function decrypt(string $payload): string
    {
        $decoded = json_decode(base64_decode($payload), true);

        if (!$this->validPayload($decoded)) {
            throw new EncryptionException('Invalid encryption payload');
        }

        if (!$this->validateMac($decoded)) {
            throw new EncryptionException('MAC verification failed');
        }

        $decrypted = openssl_decrypt(
            base64_decode($decoded['value']),
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            base64_decode($decoded['iv'])
        );

        if ($decrypted === false) {
            throw new EncryptionException('Decryption failed');
        }

        return $decrypted;
    }

    protected function initializeEncryption(): void
    {
        $this->key = $this->keyManager->getCurrentKey();
        $this->cipher = $this->options['cipher'] ?? 'aes-256-gcm';
        
        if (!in_array($this->cipher, openssl_get_cipher_methods())) {
            throw new EncryptionException('Invalid cipher method');
        }
    }

    protected function calculateMac(string $encrypted, string $iv): string
    {
        return hash_hmac(
            'sha256',
            $iv . $