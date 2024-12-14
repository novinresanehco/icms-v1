<?php

namespace App\Core\Security;

use App\Core\Audit\AuditLogger;

class EncryptionService implements EncryptionInterface
{
    private string $key;
    private string $cipher;
    private AuditLogger $audit;

    public function __construct(
        string $key,
        string $cipher,
        AuditLogger $audit
    ) {
        $this->key = $key;
        $this->cipher = $cipher;
        $this->audit = $audit;
    }

    public function encryptData(array $data): EncryptedData
    {
        try {
            // Validate data
            $this->validateDataForEncryption($data);
            
            // Prepare for encryption
            $preparedData = $this->prepareForEncryption($data);
            
            // Perform encryption
            $encrypted = $this->performEncryption($preparedData);
            
            // Verify encryption
            $this->verifyEncryption($encrypted, $data);
            
            return new EncryptedData($encrypted, $this->generateMetadata());
            
        } catch (\Exception $e) {
            $this->handleEncryptionFailure($e, $data);
            throw $e;
        }
    }

    public function decryptData(EncryptedData $data): array
    {
        try {
            // Validate encrypted data
            $this->validateEncryptedData($data);
            
            // Perform decryption
            $decrypted = $this->performDecryption($data);
            
            // Verify decryption
            $this->verifyDecryption($decrypted);
            
            return $decrypted;
            
        } catch (\Exception $e) {
            $this->handleDecryptionFailure($e, $data);
            throw $e;
        }
    }

    private function validateDataForEncryption(array $data): void
    {
        if (empty($data)) {
            throw new EncryptionException('Empty data for encryption');
        }

        if (!$this->isEncryptable($data)) {
            throw new EncryptionException('Data not encryptable');
        }
    }

    private function prepareForEncryption(array $data): string
    {
        return json_encode($data);
    }

    private function performEncryption(string $data): string
    {
        $iv = $this->generateIv();
        
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            0,
            $iv
        );
        
        if ($encrypted === false) {
            throw new EncryptionException('Encryption failed');
        }
        
        return base64_encode($iv . $encrypted);
    }

    private function verifyEncryption(string $encrypted, array $original): void
    {
        $decrypted = $this->performDecryption(new EncryptedData($encrypted));
        
        if ($decrypted !== $original) {
            throw new EncryptionException('Encryption verification failed');
        }
    }

    private function validateEncryptedData(EncryptedData $data): void
    {
        if (!$data->isValid()) {
            throw new EncryptionException('Invalid encrypted data');
        }

        if (!$this->isValidFormat($data->getData())) {
            throw new EncryptionException('Invalid encryption format');
        }
    }

    private function performDecryption(EncryptedData $data): array
    {
        $decoded = base64_decode($data->getData());
        
        $iv = substr($decoded, 0, openssl_cipher_iv_length($this->cipher));
        $encrypted = substr($decoded, openssl_cipher_iv_length($this->cipher));
        
        $decrypted = openssl_decrypt(
            $encrypted,
            $this->cipher,
            $this->key,
            0,
            $iv
        );
        
        if ($decrypted === false) {
            throw new EncryptionException('Decryption failed');
        }
        
        return json_decode($decrypted, true);
    }

    private function verifyDecryption(array $decrypted): void
    {
        if (!$this->isValidDecrypted($decrypted)) {
            throw new EncryptionException('Decryption verification failed');
        }
    }

    private function generateIv(): string
    {
        return openssl_random_pseudo_bytes(
            openssl_cipher_iv_length($this->cipher)
        );
    }

    private function generateMetadata(): array
    {
        return [
            'timestamp' => now(),
            'cipher' => $this->cipher,
            'iv_length' => openssl_cipher_iv_length($this->cipher)
        ];
    }

    private function isEncryptable(array $data