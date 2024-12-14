<?php

namespace App\Core\Security\Encryption;

class CriticalEncryptionService 
{
    private $keyManager;
    private $monitor;
    const CIPHER = 'aes-256-gcm';

    public function encrypt(array $data, string $context): string 
    {
        $operationId = $this->monitor->startEncryption();

        try {
            // Get encryption key
            $key = $this->keyManager->getActiveKey($context);
            
            // Prepare data
            $json = json_encode($data);
            if ($json === false) {
                throw new EncryptionException('JSON encode failed');
            }

            // Generate IV
            $iv = random_bytes(16);
            
            // Encrypt
            $ciphertext = openssl_encrypt(
                $json,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($ciphertext === false) {
                throw new EncryptionException('Encryption failed');
            }

            // Combine for storage
            $encrypted = base64_encode($iv . $tag . $ciphertext);
            
            $this->monitor->encryptionSuccess($operationId);
            return $encrypted;

        } catch (\Exception $e) {
            $this->monitor->encryptionFailure($operationId, $e);
            throw $e;
        }
    }

    public function decrypt(string $encrypted, string $context): array
    {
        $operationId = $this->monitor->startDecryption();

        try {
            // Get encryption key
            $key = $this->keyManager->getActiveKey($context);
            
            // Decode and extract parts
            $data = base64_decode($encrypted);
            $iv = substr($data, 0, 16);
            $tag = substr($data, 16, 16);
            $ciphertext = substr($data, 32);

            // Decrypt
            $decrypted = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            // Parse JSON
            $result = json_decode($decrypted, true);
            if ($result === null) {
                throw new EncryptionException('JSON decode failed');
            }

            $this->monitor->decryptionSuccess($operationId);
            return $result;

        } catch (\Exception $e) {
            $this->monitor->decryptionFailure($operationId, $e);
            throw $e;
        }
    }
}
