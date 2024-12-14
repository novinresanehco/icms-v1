<?php

namespace App\Core\Security\KeyManagement;

use App\Core\Security\Models\{EncryptionKey, KeyMetadata, SecurityContext};
use Illuminate\Support\Facades\{Cache, DB, Log};

class KeyManagementSystem
{
    private KeyStore $store;
    private KeyGenerator $generator;
    private AuditLogger $logger;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        KeyStore $store,
        KeyGenerator $generator,
        AuditLogger $logger,
        SecurityConfig $config,
        MetricsCollector $metrics
    ) {
        $this->store = $store;
        $this->generator = $generator;
        $this->logger = $logger;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function getCurrentKey(string $purpose): EncryptionKey
    {
        $cacheKey = "current_key:{$purpose}";
        
        return Cache::remember($cacheKey, 300, function() use ($purpose) {
            return $this->store->getCurrentKey($purpose);
        });
    }

    public function rotateKeys(SecurityContext $context): void
    {
        DB::beginTransaction();
        
        try {
            // Generate new keys
            $newKeys = $this->generateNewKeys();
            
            // Validate new keys
            $this->validateNewKeys($newKeys);
            
            // Store new keys
            $this->storeNewKeys($newKeys, $context);
            
            // Update key references
            $this->updateKeyReferences($newKeys);
            
            // Mark old keys for deletion
            $this->markOldKeysForDeletion();
            
            DB::commit();
            
            // Invalidate key cache
            $this->invalidateKeyCache();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleKeyRotationFailure($e, $context);
        }
    }

    public function getKey(string $keyId): EncryptionKey
    {
        $cacheKey = "key:{$keyId}";
        
        return Cache::remember($cacheKey, 300, function() use ($keyId) {
            return $this->store->getKey($keyId);
        });
    }

    private function generateNewKeys(): array
    {
        $keys = [];
        
        foreach ($this->config->getKeyPurposes() as $purpose) {
            $keys[$purpose] = $this->generator->generateKey(
                $purpose,
                $this->config->getKeyParameters($purpose)
            );
        }
        
        return $keys;
    }

    private function validateNewKeys(array $keys): void
    {
        foreach ($keys as $purpose => $key) {
            if (!$this->validateKey($key, $purpose)) {
                throw new KeyManagementException("Key validation failed for {$purpose}");
            }
        }
    }

    private function validateKey(EncryptionKey $key, string $purpose): bool
    {
        // Validate key strength
        if (!$this->validateKeyStrength($key)) {
            return false;
        }
        
        // Validate key format
        if (!$this->validateKeyFormat($key)) {
            return false;
        }
        
        // Validate key metadata
        if (!$this->validateKeyMetadata($key, $purpose)) {
            return false;
        }
        
        return true;
    }

    private function storeNewKeys(array $keys, SecurityContext $context): void
    {
        foreach ($keys as $purpose => $key) {
            $metadata = new KeyMetadata([
                'created_at' => time(),
                'created_by' => $context->getUserId(),
                'purpose' => $purpose,
                'version' => $this->getNextKeyVersion($purpose),
                'algorithm' => $key->getAlgorithm(),
                'strength' => $key->getStrength()
            ]);
            
            $this->store->storeKey($key, $metadata);
            
            $this->logger->logKeyCreation($context, $metadata);
        }
    }

    private function updateKeyReferences(array $newKeys): void
    {
        foreach ($newKeys as $purpose => $key) {
            $this->store->setCurrentKey($purpose, $key->getId());
        }
    }

    private function markOldKeysForDeletion(): void
    {
        $retentionPeriod = $this->config->getKeyRetentionPeriod();
        $threshold = time() - $retentionPeriod;
        
        $oldKeys = $this->store->getKeysBefore($threshold);
        
        foreach ($oldKeys as $key) {
            $this->store->markForDeletion($key->getId());
        }
    }

    private function handleKeyRotationFailure(\Exception $e, SecurityContext $context): void
    {
        $this->logger->logSecurityEvent('key_rotation_failed', [
            'error' => $e->getMessage(),
            'context' => $context,
            'timestamp' => time()
        ]);

        $this->metrics->incrementCounter('key_rotation_failures');
        
        throw new KeyManagementException(
            'Key rotation failed: ' . $e->getMessage(),
            previous: $e
        );
    }

    private function invalidateKeyCache(): void
    {
        $pattern = 'key:*';
        Cache::deletePattern($pattern);
        
        $this->logger->logSecurityEvent('key_cache_invalidated', [
            'pattern' => $pattern,
            'timestamp' => time()
        ]);
    }

    private function getNextKeyVersion(string $purpose): int
    {
        $currentKey = $this->store->getCurrentKey($purpose);
        return $currentKey ? $currentKey->getVersion() + 1 : 1;
    }
}
