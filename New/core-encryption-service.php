<?php

namespace App\Core\Security;

use App\Core\Interfaces\EncryptionInterface;
use App\Core\Services\{KeyManagementService, AuditService};
use App\Core\Exceptions\{EncryptionException, SecurityException};

/**
 * Core encryption service handling all critical data encryption operations.
 * Implements comprehensive security measures and audit logging.
 */
class EncryptionService implements EncryptionInterface 
{
    protected KeyManagementService $keyManager;
    protected AuditService $auditService;
    protected string $cipher = 'aes-256-gcm';
    protected int $keyRotationInterval = 86400; // 24 hours

    public function __construct(
        KeyManagementService $keyManager,
        AuditService $auditService
    ) {
        $this->keyManager = $keyManager;
        $this->auditService = $auditService;
    }

    /**
     * Encrypts data with comprehensive security measures and audit logging.
     *
     * @param mixed $data Data to encrypt
     * @throws EncryptionException If encryption fails
     * @return string Encrypted data
     */
    public function encrypt($data): string
    {
        try {
            // Generate a cryptographically secure IV
            $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
            
            // Get current encryption key
            $key = $this->keyManager->getCurrentKey();
            
            // Encrypt data with authenticated encryption
            $encrypted = openssl_encrypt(
                serialize($data),
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption failed');
            }

            // Combine IV, tag and ciphertext for storage
            $result = base64_encode($iv . $tag . $encrypted);
            
            // Log successful encryption
            $this->auditService->logSecurityEvent('encryption_success', [
                'algorithm' => $this->cipher
            ]);
            
            return $result;

        } catch (\Exception $e) {
            $this->auditService->logSecurityEvent('encryption_failure', [
                'error' => $e->getMessage()
            ]);
            throw new EncryptionException('Encryption failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decrypts data with integrity verification and audit logging.
     *
     * @param string $encryptedData Data to decrypt
     * @throws EncryptionException If decryption or verification fails
     * @return mixed Decrypted data
     */
    public function decrypt(string $encryptedData): mixed
    {
        try {
            // Decode from storage format
            $data = base64_decode($encryptedData, true);
            if ($data === false) {
                throw new EncryptionException('Invalid encrypted data format');
            }

            // Extract components
            $ivLength = openssl_cipher_iv_length($this->cipher);
            $iv = substr($data, 0, $ivLength);
            $tag = substr($data, $ivLength, 16);
            $ciphertext = substr($data, $ivLength + 16);

            // Get decryption key
            $key = $this->keyManager->getCurrentKey();

            // Decrypt with authenticated encryption
            $decrypted = openssl_decrypt(
                $ciphertext,
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed - data may be tampered');
            }

            $result = unserialize($decrypted);

            // Log successful decryption
            $this->auditService->logSecurityEvent('decryption_success');

            return $result;

        } catch (\Exception $e) {
            $this->auditService->logSecurityEvent('decryption_failure', [
                'error' => $e->getMessage()
            ]);
            throw new EncryptionException('Decryption failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Rotates encryption keys based on defined interval.
     *
     * @throws SecurityException If key rotation fails
     * @return void
     */
    public function rotateKeys(): void
    {
        try {
            $this->keyManager->rotateKeys();
            $this->auditService->logSecurityEvent('key_rotation_success');
        } catch (\Exception $e) {
            $this->auditService->logSecurityEvent('key_rotation_failure', [
                'error' => $e->getMessage()
            ]);
            throw new SecurityException('Key rotation failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
