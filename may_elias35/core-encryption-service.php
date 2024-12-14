<?php

namespace App\Core\Security\Encryption;

use App\Core\Security\KeyManager;
use App\Core\Monitoring\MetricsCollector;
use App\Core\Audit\AuditLogger;

class CoreEncryptionService implements EncryptionInterface
{
    private KeyManager $keyManager;
    private MetricsCollector $metrics;
    private AuditLogger $audit;

    private const CIPHER = 'aes-256-gcm';
    private const KEY_ROTATION_INTERVAL = 86400; // 24 hours
    private const MAX_KEY_AGE = 604800; // 7 days

    public function __construct(
        KeyManager $keyManager,
        MetricsCollector $metrics, 
        AuditLogger $audit
    ) {
        $this->keyManager = $keyManager;
        $this->metrics = $metrics;
        $this->audit = $audit;
    }

    public function encrypt(string $data, Context $context): EncryptedData 
    {
        $operationId = $this->metrics->startOperation();

        try {
            // Validate encryption context
            $this->validateContext($context);

            // Get encryption key
            $key = $this->getEncryptionKey($context);

            // Generate IV
            $iv = random_bytes(16);

            // Encrypt data
            $encryptedData = $this->performEncryption($data, $key, $iv);

            // Generate MAC
            $mac = $this->generateMAC($encryptedData, $key);

            // Create result
            $result = new EncryptedData(
                data: $encryptedData,
                iv: $iv,
                mac: $mac,
                context: $context
            );

            // Verify encryption
            $this->verifyEncryption($result, $data);

            $this->audit->logEncryption($context);

            return $result;

        } catch (\Exception $e) {
            $this->handleEncryptionFailure($e, $context);
            throw $e;
        } finally {
            $this->metrics->endOperation($operationId);
        }
    }

    public function decrypt(EncryptedData $encryptedData, Context $context): string 
    {
        $operationId = $this->metrics->startOperation();

        try {
            // Validate decryption context
            $this->validateContext($context);

            // Verify MAC
            if (!$this->verifyMAC($encryptedData)) {
                throw new IntegrityException('MAC verification failed');
            }

            // Get decryption key
            $key = $this->getDecryptionKey($encryptedData->context);

            // Decrypt data
            $decryptedData = $this->performDecryption(
                $encryptedData->data,
                $key,
                $encryptedData->iv
            );

            // Verify decryption
            $this->verifyDecryption($decryptedData, $encryptedData);

            $this->audit->logDecryption($context);

            return $decryptedData;

        } catch (\Exception $e) {
            $this->handleDecryptionFailure($e, $context);
            throw $e;
        } finally {
            $this->metrics->endOperation($operationId);
        }
    }

    private function validateContext(Context $context): void
    {
        if (!$context->isValid()) {
            throw new ValidationException('Invalid encryption context');
        }

        if ($this->keyManager->isContextCompromised($context)) {
            throw new SecurityException('Security context compromised');
        }
    }

    private function getEncryptionKey(Context $context): string
    {
        $key = $this->keyManager->getCurrentKey($context);

        if ($this->shouldRotateKey($key)) {
            $key = $this->keyManager->rotateKey($context);
            $this->audit->logKeyRotation($context);
        }

        return $key;
    }

    private function performEncryption(
        string $data,
        string $key,
        string $iv
    ): string {
        $tag = '';
        $encryptedData = openssl_encrypt(
            $data,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($encryptedData === false) {
            throw new EncryptionException('Encryption failed');
        }

        return $encryptedData . $tag;
    }

    private function generateMAC(string $data, string $key): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }

    private function verifyEncryption(
        EncryptedData $result,
        string $originalData
    ): void {
        $decrypted = $this->decrypt($result, $result->context);

        if ($decrypted !== $originalData) {
            throw new EncryptionException('Encryption verification failed');
        }
    }

    private function verifyMAC(EncryptedData $encryptedData): bool
    {
        $key = $this->getDecryptionKey($encryptedData->context);
        $computedMac = $this->generateMAC($encryptedData->data, $key);

        return hash_equals($computedMac, $encryptedData->mac);
    }

    private function performDecryption(
        string $data,
        string $key,
        string $iv
    ): string {
        $tag = substr($data, -16);
        $ciphertext = substr($data, 0, -16);

        $decryptedData = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decryptedData === false) {
            throw new DecryptionException('Decryption failed');
        }

        return $decryptedData;
    }

    private function verifyDecryption(
        string $decryptedData,
        EncryptedData $original
    ): void {
        $encryptedCheck = $this->encrypt($decryptedData, $original->context);

        if (!hash_equals($original->mac, $encryptedCheck->mac)) {
            throw new IntegrityException('Decryption verification failed');
        }
    }

    private function shouldRotateKey(string $key): bool
    {
        $keyAge = $this->keyManager->getKeyAge($key);
        return $keyAge >= self::KEY_ROTATION_INTERVAL;
    }

    private function handleEncryptionFailure(
        \Exception $e,
        Context $context
    ): void {
        $this->audit->logFailure('encryption_failed', [
            'context' => $context->getId(),
            'error' => $e->getMessage()
        ]);

        if ($e instanceof SecurityException) {
            $this->keyManager->handleSecurityFailure($context);
        }

        $this->metrics->recordFailure('encryption', [
            'error_type' => get_class($e),
            'context' => $context->getId()
        ]);
    }

    private function handleDecryptionFailure(
        \Exception $e,
        Context $context
    ): void {
        $this->audit->logFailure('decryption_failed', [
            'context' => $context->getId(),
            'error' => $e->getMessage()
        ]);

        if ($e instanceof IntegrityException) {
            $this->keyManager->handleIntegrityFailure($context);
        }

        $this->metrics->recordFailure('decryption', [
            'error_type' => get_class($e),
            'context' => $context->getId()
        ]);
    }
}
