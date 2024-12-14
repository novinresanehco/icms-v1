<?php

namespace App\Core\Security;

class EncryptionService implements EncryptionServiceInterface 
{
    private KeyRotationManager $keyManager;
    private ValidationService $validator;
    private AuditLogger $logger;

    protected string $encryptedPayload;
    protected string $iv;
    protected string $mac;

    public function validateEncryption(array $data): bool
    {
        try {
            $operationId = $this->logger->startOperation('encryption_validation');

            $this->validateEncryptionPayload($data);
            $this->verifyMacIntegrity();
            $this->validateKeyRotation();
            $this->checkEncryptionStrength();

            $this->logger->logSuccess($operationId);
            return true;

        } catch (\Throwable $e) {
            $this->handleEncryptionFailure($e, $operationId);
            throw $e;
        }
    }

    protected function validateEncryptionPayload(array $data): void 
    {
        if (!isset($data['encrypted_payload'], $data['iv'], $data['mac'])) {
            throw new EncryptionException('Invalid encryption data structure');
        }

        $this->encryptedPayload = $data['encrypted_payload'];
        $this->iv = $data['iv'];
        $this->mac = $data['mac'];

        if (!$this->validator->validateEncryptionFormat($data)) {
            throw new EncryptionException('Invalid encryption format');
        }
    }

    protected function verifyMacIntegrity(): void 
    {
        $calculatedMac = hash_hmac(
            'sha256',
            $this->iv . $this->encryptedPayload,
            $this->keyManager->getCurrentKey(),
            true
        );

        if (!hash_equals($calculatedMac, base64_decode($this->mac))) {
            throw new EncryptionException('MAC verification failed');
        }
    }

    protected function validateKeyRotation(): void
    {
        if (!$this->keyManager->validateKeyAge()) {
            throw new EncryptionException('Encryption key expired');
        }
    }

    protected function checkEncryptionStrength(): void
    {
        if (!$this->validator->validateEncryptionStrength([
            'payload' => $this->encryptedPayload,
            'iv' => $this->iv
        ])) {
            throw new EncryptionException('Insufficient encryption strength');
        }
    }

    protected function handleEncryptionFailure(\Throwable $e, string $operationId): void 
    {
        $this->logger->logFailure([
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'severity' => 'CRITICAL'
        ]);

        $this->logger->triggerSecurityAlert([
            'type' => 'encryption_failure',
            'error' => $e->getMessage(),
            'timestamp' => time()
        ]);
    }
}
