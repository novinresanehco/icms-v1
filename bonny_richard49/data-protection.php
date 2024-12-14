<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Cache, Log, DB};
use App\Core\Interfaces\DataProtectionInterface;
use App\Core\Exceptions\{EncryptionException, IntegrityException};

class DataProtectionService implements DataProtectionInterface
{
    private EncryptionManager $encryption;
    private KeyManager $keyManager;
    private IntegrityVerifier $integrityVerifier;
    private DataProtectionConfig $config;

    public function __construct(
        EncryptionManager $encryption,
        KeyManager $keyManager,
        IntegrityVerifier $integrityVerifier,
        DataProtectionConfig $config
    ) {
        $this->encryption = $encryption;
        $this->keyManager = $keyManager;
        $this->integrityVerifier = $integrityVerifier;
        $this->config = $config;
    }

    public function protectData(array $data, array $context): ProtectedData
    {
        DB::beginTransaction();
        
        try {
            // Generate encryption key
            $key = $this->keyManager->generateKey();
            
            // Validate data before encryption
            $this->validateData($data);
            
            // Calculate integrity hash
            $hash = $this->integrityVerifier->calculateHash($data);
            
            // Encrypt data
            $encrypted = $this->encryption->encrypt($data, $key);
            
            // Create protected data container
            $protected = new ProtectedData(
                data: $encrypted,
                key: $key,
                hash: $hash,
                context: $context
            );
            
            // Verify protection
            $this->verifyProtection($protected);
            
            DB::commit();
            return $protected;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new EncryptionException(
                'Data protection failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function unprotectData(ProtectedData $protected): array
    {
        try {
            // Verify integrity before decryption
            if (!$this->verifyIntegrity($protected)) {
                throw new IntegrityException('Data integrity verification failed');
            }
            
            // Decrypt data
            $decrypted = $this->encryption->decrypt(
                $protected->getData(),
                $protected->getKey()
            );
            
            // Validate decrypted data
            $this->validateData($decrypted);
            
            return $decrypted;
            
        } catch (\Throwable $e) {
            throw new EncryptionException(
                'Data unprotection failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function rotateKey(ProtectedData $protected): ProtectedData
    {
        DB::beginTransaction();
        
        try {
            // Generate new key
            $newKey = $this->keyManager->generateKey();
            
            // Decrypt with old key
            $decrypted = $this->encryption->decrypt(
                $protected->getData(),
                $protected->getKey()
            );
            
            // Encrypt with new key
            $reencrypted = $this->encryption->encrypt($decrypted, $newKey);
            
            // Create new protected data
            $rotated = new ProtectedData(
                data: $reencrypted,
                key: $newKey,
                hash: $protected->getHash(),
                context: $protected->getContext()
            );
            
            // Verify rotated protection
            $this->verifyProtection($rotated);
            
            DB::commit();
            return $rotated;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new EncryptionException(
                'Key rotation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function validateData(array $data): void
    {
        // Check data size
        if ($this->exceedsSizeLimit($data)) {
            throw new EncryptionException('Data size exceeds limit');
        }
        
        // Validate data structure
        if (!$this->hasValidStructure($data)) {
            throw new EncryptionException('Invalid data structure');
        }
        
        // Check for prohibited data types
        if ($this->containsProhibitedTypes($data)) {
            throw new EncryptionException('Data contains prohibited types');
        }
    }

    private function verifyProtection(ProtectedData $protected): void
    {
        // Verify encryption
        if (!$this->verifyEncryption($protected)) {
            throw new EncryptionException('Encryption verification failed');
        }
        
        // Verify integrity
        if (!$this->verifyIntegrity($protected)) {
            throw new IntegrityException('Integrity verification failed');
        }
        
        // Verify key security
        if (!$this->verifyKeyStrength($protected->getKey())) {
            throw new EncryptionException('Key strength verification failed');
        }
    }

    private function verifyEncryption(ProtectedData $protected): bool
    {
        // Verify encryption algorithm
        if (!$this->verifyAlgorithm($protected)) {
            return false;
        }
        
        // Verify key usage
        if (!$this->verifyKeyUsage($protected)) {
            return false;
        }
        
        // Verify encryption mode
        return $this->verifyEncryptionMode($protected);
    }

    private function verifyIntegrity(ProtectedData $protected): bool
    {
        // Calculate current hash
        $currentHash = $this->integrityVerifier->calculateHash(
            $protected->getData()
        );
        
        // Compare with stored hash
        return hash_equals($protected->getHash(), $currentHash);
    }

    private function verifyKeyStrength(string $key): bool
    {
        return $this->keyManager->validateKeyStrength($key);
    }

    private function exceedsSizeLimit(array $data): bool
    {
        $size = strlen(serialize($data));
        return $size > $this->config->getMaxDataSize();
    }

    private function hasValidStructure(array $data): bool
    {
        return $this->validateStructureRecursive($data);
    }

    private function validateStructureRecursive(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (!$this->validateStructureRecursive($value)) {
                    return false;
                }
            } elseif (!$this->isValidDataType($value)) {
                return false;
            }
        }
        return true;
    }

    private function containsProhibitedTypes(array $data): bool
    {
        foreach ($data as $value) {
            if (is_resource($value) || is_object($value)) {
                return true;
            }
            if (is_array($value) && $this->containsProhibitedTypes($value)) {
                return true;
            }
        }
        return false;
    }

    private function isValidDataType($value): bool
    {
        return is_scalar($value) || is_null($value);
    }

    private function verifyAlgorithm(ProtectedData $protected): bool
    {
        return $this->encryption->verifyAlgorithm(
            $protected->getData()
        );
    }

    private function verifyKeyUsage(ProtectedData $protected): bool
    {
        return $this->keyManager->verifyKeyUsage(
            $protected->getKey()
        );
    }

    private function verifyEncryptionMode(ProtectedData $protected): bool
    {
        return $this->encryption->verifyMode(
            $protected->getData()
        );
    }
}
