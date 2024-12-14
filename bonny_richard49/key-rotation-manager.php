<?php

namespace App\Core\Security;

class KeyRotationManager implements KeyRotationInterface 
{
    private KeyStore $keyStore;
    private KeyGenerator $generator;
    private AuditLogger $logger;
    private array $config;

    public function rotateKeys(): void 
    {
        $operationId = $this->logger->startOperation('key_rotation');

        try {
            $this->validateCurrentKeys();
            $this->generateNewKeys();
            $this->updateActiveKeys();
            $this->archiveOldKeys();
            $this->verifyKeyRotation();

            $this->logger->logSuccess($operationId);

        } catch (\Throwable $e) {
            $this->handleRotationFailure($e, $operationId);
            throw $e;
        }
    }

    public function getCurrentKey(): string 
    {
        if (!$this->keyStore->hasActiveKey()) {
            throw new KeyManagementException('No active encryption key');
        }

        return $this->keyStore->getActiveKey();
    }

    public function validateKeyAge(): bool 
    {
        $keyAge = $this->keyStore->getActiveKeyAge();
        return $keyAge <= $this->config['max_key_age'];
    }

    protected function validateCurrentKeys(): void 
    {
        if (!$this->keyStore->validateKeys()) {
            throw new KeyManagementException('Current keys validation failed');
        }
    }

    protected function generateNewKeys(): void 
    {
        $this->newKeys = $this->generator->generateKeySet([
            'algorithm' => 'aes-256-gcm',
            'iterations' => 100000,
            'memory_cost' => 4096
        ]);

        if (!$this->validateGeneratedKeys($this->newKeys)) {
            throw new KeyManagementException('Generated keys validation failed');
        }
    }

    protected function updateActiveKeys(): void 
    {
        $this->keyStore->setActiveKeys($this->newKeys);
    }

    protected function archiveOldKeys(): void 
    {
        $oldKeys = $this->keyStore->getActiveKeys();
        $this->keyStore->archiveKeys($oldKeys);
    }

    protected function verifyKeyRotation(): void 
    {
        if (!$this->keyStore->verifyRotation($this->newKeys)) {
            throw new KeyManagementException('Key rotation verification failed');
        }
    }

    protected function validateGeneratedKeys(array $keys): bool
    {
        return $this->generator->validateKeyStrength($keys) && 
               $this->generator->validateKeyFormat($keys);
    }

    protected function handleRotationFailure(\Throwable $e, string $operationId): void 
    {
        $this->logger->logFailure([
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'severity' => 'CRITICAL'
        ]);

        $this->logger->triggerSecurityAlert([
            'type' => 'key_rotation_failure',
            'error' => $e->getMessage(),
            'timestamp' => time()
        ]);
    }
}
