```php
<?php
namespace App\Core\Encryption;

class EncryptionManager implements EncryptionInterface 
{
    private KeyManager $keyManager;
    private CipherService $cipher;
    private SecurityValidator $validator;
    private AuditLogger $logger;

    public function encrypt(mixed $data, array $context = []): EncryptedData 
    {
        $operationId = $this->keyManager->generateOperationId();
        
        try {
            $this->validateData($data);
            $key = $this->keyManager->getEncryptionKey($context);
            
            $encrypted = $this->cipher->encrypt(
                $this->prepareData($data),
                $key,
                $iv = $this->cipher->generateIv()
            );
            
            $this->logger->logEncryption($operationId, $context);
            
            return new EncryptedData($encrypted, $iv, $operationId);
        } catch (\Exception $e) {
            $this->handleEncryptionFailure($e, $operationId);
            throw new EncryptionException('Encryption failed', 0, $e);
        }
    }

    public function decrypt(EncryptedData $data, array $context = []): mixed 
    {
        try {
            $key = $this->keyManager->getDecryptionKey($context);
            
            $decrypted = $this->cipher->decrypt(
                $data->content,
                $key,
                $data->iv
            );
            
            $this->logger->logDecryption($data->operationId, $context);
            
            return $this->restoreData($decrypted);
        } catch (\Exception $e) {
            $this->handleDecryptionFailure($e, $data->operationId);
            throw new DecryptionException('Decryption failed', 0, $e);
        }
    }

    private function validateData(mixed $data): void 
    {
        if (!$this->validator->validateEncryptionData($data)) {
            throw new ValidationException('Invalid data for encryption');
        }
    }
}

class KeyManager implements KeyManagerInterface 
{
    private KeyStorage $storage;
    private SecurityManager $security;
    private RotationScheduler $rotator;
    private AuditLogger $logger;
    
    public function generateKey(array $context = []): Key 
    {
        $keyId = $this->security->generateKeyId();
        
        try {
            $key = $this->security->generateSecureKey();
            $encryptedKey = $this->encryptMasterKey($key);
            
            $this->storage->storeKey($keyId, $encryptedKey, $context);
            $this->logger->logKeyGeneration($keyId, $context);
            
            return new Key($keyId, $key);
        } catch (\Exception $e) {
            $this->handleKeyGenerationFailure($e, $keyId);
            throw new KeyGenerationException('Key generation failed', 0, $e);
        }
    }

    public function rotateKeys(): void 
    {
        $rotationId = $this->security->generateRotationId();
        
        try {
            $keys = $this->storage->getActiveKeys();
            
            foreach ($keys as $key) {
                if ($this->rotator->shouldRotate($key)) {
                    $this->rotateKey($key, $rotationId);
                }
            }
            
            $this->logger->logKeyRotation($rotationId);
        } catch (\Exception $e) {
            $this->handleRotationFailure($e, $rotationId);
            throw new KeyRotationException('Key rotation failed', 0, $e);
        }
    }

    private function rotateKey(Key $key, string $rotationId): void 
    {
        $newKey = $this->generateKey(['rotation_id' => $rotationId]);
        $this->storage->markKeyForRotation($key->id, $newKey->id);
        $this->scheduleDataReEncryption($key->id, $newKey->id);
    }
}

class CipherService implements CipherInterface 
{
    private string $algorithm = 'aes-256-gcm';
    private SecurityManager $security;
    private MetricsCollector $metrics;

    public function encrypt(string $data, Key $key, string $iv): string 
    {
        try {
            $startTime = microtime(true);
            
            $tag = '';
            $encrypted = openssl_encrypt(
                $data,
                $this->algorithm,
                $key->value,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($encrypted === false) {
                throw new EncryptionException('OpenSSL encryption failed');
            }
            
            $this->metrics->recordEncryption(microtime(true) - $startTime);
            
            return base64_encode($encrypted . '::' . $tag);
        } catch (\Exception $e) {
            $this->handleCipherFailure($e, 'encryption');
            throw $e;
        }
    }

    public function decrypt(string $data, Key $key, string $iv): string 
    {
        try {
            $startTime = microtime(true);
            
            [$encrypted, $tag] = $this->parseEncryptedData($data);
            
            $decrypted = openssl_decrypt(
                $encrypted,
                $this->algorithm,
                $key->value,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($decrypted === false) {
                throw new DecryptionException('OpenSSL decryption failed');
            }
            
            $this->metrics->recordDecryption(microtime(true) - $startTime);
            
            return $decrypted;
        } catch (\Exception $e) {
            $this->handleCipherFailure($e, 'decryption');
            throw $e;
        }
    }
}
```
