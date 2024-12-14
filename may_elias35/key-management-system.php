```php
namespace App\Core\Security\KeyManagement;

use App\Core\Security\SecurityManager;
use App\Core\Infrastructure\Monitoring\MetricsSystem;
use App\Core\Security\Encryption\KeyGenerator;

class KeyManagementSystem
{
    private SecurityManager $security;
    private MetricsSystem $metrics;
    private KeyGenerator $keyGenerator;
    private AuditLogger $auditLogger;
    
    private const KEY_ROTATION_INTERVAL = 86400; // 24 hours
    private const ENCRYPTION_ALGORITHM = 'AES-256-GCM';

    public function generateKey(string $purpose): EncryptionKey
    {
        DB::beginTransaction();
        
        try {
            // Generate cryptographically secure key
            $keyMaterial = $this->keyGenerator->generateSecureKey(
                self::ENCRYPTION_ALGORITHM
            );
            
            // Create key metadata
            $metadata = $this->createKeyMetadata($purpose);
            
            // Secure key storage
            $key = new EncryptionKey([
                'material' => $keyMaterial,
                'metadata' => $metadata,
                'status' => 'active',
                'created_at' => now()
            ]);
            
            // Store key securely
            $this->securelyStoreKey($key);
            
            DB::commit();
            $this->auditLogger->logKeyGeneration($metadata);
            
            return $key;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleKeyGenerationFailure($e);
            throw $e;
        }
    }

    public function rotateKey(string $keyId): EncryptionKey
    {
        DB::beginTransaction();
        
        try {
            // Validate current key
            $currentKey = $this->validateCurrentKey($keyId);
            
            // Generate new key
            $newKey = $this->generateKey($currentKey->getPurpose());
            
            // Re-encrypt sensitive data
            $this->reencryptData($currentKey, $newKey);
            
            // Update key status
            $this->updateKeyStatus($currentKey, 'retired');
            
            DB::commit();
            $this->auditLogger->logKeyRotation($currentKey->getId(), $newKey->getId());
            
            return $newKey;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleKeyRotationFailure($e, $keyId);
            throw $e;
        }
    }

    private function createKeyMetadata(string $purpose): KeyMetadata
    {
        return new KeyMetadata([
            'id' => $this->generateKeyId(),
            'purpose' => $purpose,
            'algorithm' => self::ENCRYPTION_ALGORITHM,
            'rotation_due' => now()->addSeconds(self::KEY_ROTATION_INTERVAL),
            'security_level' => $this->determineSecurityLevel($purpose),
            'access_control' => $this->generateAccessControl($purpose)
        ]);
    }

    private function securelyStoreKey(EncryptionKey $key): void
    {
        // Encrypt key material with master key
        $encryptedMaterial = $this->security->encryptWithMasterKey(
            $key->getMaterial()
        );
        
        // Store encrypted key with metadata
        $this->security->storeEncryptedKey($key->getId(), [
            'material' => $encryptedMaterial,
            'metadata' => $key->getMetadata()
        ]);
        
        // Update key registry
        $this->updateKeyRegistry($key);
    }

    private function validateCurrentKey(string $keyId): EncryptionKey
    {
        $key = $this->security->retrieveKey($keyId);
        
        if (!$key->isActive()) {
            throw new KeyValidationException('Key is not active');
        }

        if (!$this->validateKeyIntegrity($key)) {
            throw new KeyValidationException('Key integrity check failed');
        }

        return $key;
    }

    private function reencryptData(
        EncryptionKey $oldKey,
        EncryptionKey $newKey
    ): void {
        // Get data encrypted with old key
        $encryptedData = $this->security->getEncryptedData($oldKey->getId());
        
        foreach ($encryptedData as $data) {
            // Decrypt with old key
            $decrypted = $this->security->decrypt($data, $oldKey);
            
            // Re-encrypt with new key
            $reencrypted = $this->security->encrypt($decrypted, $newKey);
            
            // Update storage
            $this->security->updateEncryptedData(
                $data->getId(),
                $reencrypted,
                $newKey->getId()
            );
        }
    }

    private function validateKeyIntegrity(EncryptionKey $key): bool
    {
        // Verify key checksum
        if (!$this->verifyKeyChecksum($key)) {
            return false;
        }

        // Verify key metadata integrity
        if (!$this->verifyMetadataIntegrity($key->getMetadata())) {
            return false;
        }

        // Verify key usage compliance
        if (!$this->verifyKeyUsageCompliance($key)) {
            return false;
        }

        return true;
    }

    private function handleKeyGenerationFailure(\Exception $e): void
    {
        $this->auditLogger->logSecurityIncident(
            'key_generation_failure',
            [
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString()
            ]
        );

        $this->metrics->recordSecurityEvent(
            'key_generation_failure',
            ['timestamp' => now()]
        );
    }

    private function handleKeyRotationFailure(\Exception $e, string $keyId): void
    {
        $this->auditLogger->logSecurityIncident(
            'key_rotation_failure',
            [
                'key_id' => $keyId,
                'error' => $e->getMessage(),
                'stack_trace' => $e->getTraceAsString()
            ]
        );

        // Execute emergency protocols if needed
        if ($this->isEmergencyProtocolRequired($e)) {
            $this->executeEmergencyProtocol($keyId);
        }
    }
}
```
