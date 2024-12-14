<?php

namespace App\Core\Security\Encryption;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\KeyManagement\KeyRotationService;
use App\Core\Audit\AuditLogger;
use App\Exceptions\EncryptionException;

class EncryptionService implements EncryptionInterface
{
    private KeyRotationService $keyRotation;
    private AuditLogger $auditLogger;
    private array $config;

    private const KEY_CACHE_TTL = 3600;
    private const ENCRYPTION_ALGO = 'aes-256-gcm';
    private const HASH_ALGO = 'sha384';

    public function encryptData(string $data, array $context = []): EncryptedData
    {
        try {
            // Get current encryption key
            $key = $this->getCurrentKey();

            // Generate random IV
            $iv = random_bytes(16);

            // Add authentication data
            $authData = $this->generateAuthData($context);

            // Encrypt
            $ciphertext = openssl_encrypt(
                $data,
                self::ENCRYPTION_ALGO,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $authData
            );

            if ($ciphertext === false) {
                throw new EncryptionException('Encryption failed');
            }

            // Combine IV, tag and ciphertext
            $encrypted = base64_encode($iv . $tag . $ciphertext);

            // Generate integrity hash
            $hash = $this->generateIntegrityHash($encrypted, $authData);

            return new EncryptedData($encrypted, $hash, $context);

        } catch (\Throwable $e) {
            $this->handleEncryptionFailure('encrypt', $e, $context);
            throw new EncryptionException('Data encryption failed', 0, $e);
        }
    }

    public function decryptData(EncryptedData $encryptedData): string
    {
        try {
            // Verify integrity first
            $this->verifyIntegrity($encryptedData);

            // Get key
            $key = $this->getCurrentKey();

            // Decode and extract components
            $combined = base64_decode($encryptedData->data);
            $iv = substr($combined, 0, 16);
            $tag = substr($combined, 16, 16);
            $ciphertext = substr($combined, 32);

            // Verify authentication data
            $authData = $this->generateAuthData($encryptedData->context);

            // Decrypt
            $decrypted = openssl_decrypt(
                $ciphertext,
                self::ENCRYPTION_ALGO,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $authData
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            return $decrypted;

        } catch (\Throwable $e) {
            $this->handleEncryptionFailure('decrypt', $e, $encryptedData->context);
            throw new EncryptionException('Data decryption failed', 0, $e);
        }
    }

    public function verifyIntegrity(EncryptedData $encryptedData): void
    {
        $computedHash = $this->generateIntegrityHash(
            $encryptedData->data,
            $encryptedData->context
        );

        if (!hash_equals($computedHash, $encryptedData->hash)) {
            $this->auditLogger->logSecurityEvent(
                'integrity_violation',
                ['context' => $encryptedData->context]
            );
            throw new IntegrityException('Data integrity verification failed');
        }
    }

    private function getCurrentKey(): string
    {
        return Cache::remember('current_encryption_key', self::KEY_CACHE_TTL, function() {
            return $this->keyRotation->getCurrentKey();
        });
    }

    private function generateAuthData(array $context): string
    {
        return hash_hmac(
            self::HASH_ALGO,
            json_encode($context),
            $this->getCurrentKey(),
            true
        );
    }

    private function generateIntegrityHash(string $data, array $context): string
    {
        $authData = $this->generateAuthData($context);
        return hash_hmac(self::HASH_ALGO, $data . $authData, $this->getCurrentKey());
    }

    private function handleEncryptionFailure(string $operation, \Throwable $e, array $context): void
    {
        $this->auditLogger->logSecurityEvent(
            'encryption_failure',
            [
                'operation' => $operation,
                'error' => $e->getMessage(),
                'context' => $context
            ],
            5 // High severity
        );

        // Clear key cache on failure
        Cache::forget('current_encryption_key');
    }

    public function rotateKeys(): void
    {
        try {
            // Generate new key
            $this->keyRotation->rotateKeys();

            // Clear key cache
            Cache::forget('current_encryption_key');

            // Log rotation
            $this->auditLogger->logSecurityEvent(
                'key_rotation',
                ['timestamp' => time()],
                3 // Medium severity
            );

        } catch (\Throwable $e) {
            $this->handleEncryptionFailure('key_rotation', $e, []);
            throw new EncryptionException('Key rotation failed', 0, $e);
        }
    }

    public function reEncryptData(string $identifier, callable $dataProvider): void
    {
        try {
            // Get data
            $data = $dataProvider();

            // Decrypt with old key
            $decrypted = $this->decryptData($data);

            // Encrypt with new key
            $reEncrypted = $this->encryptData($decrypted, ['source' => $identifier]);

            // Store updated data
            $dataProvider($reEncrypted);

            // Log re-encryption
            $this->auditLogger->logSecurityEvent(
                'data_reencryption',
                ['identifier' => $identifier],
                2 // Low severity
            );

        } catch (\Throwable $e) {
            $this->handleEncryptionFailure('reencrypt', $e, ['identifier' => $identifier]);
            throw new EncryptionException('Data re-encryption failed', 0, $e);
        }
    }
}
