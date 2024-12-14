```php
namespace App\Core\Security;

use App\Core\Security\KeyManager;
use App\Core\Monitoring\MonitoringService;

class EncryptionService
{
    private KeyManager $keyManager;
    private MonitoringService $monitor;
    
    private const CIPHER = 'aes-256-gcm';
    private const KEY_SIZE = 32;
    private const TAG_LENGTH = 16;

    public function encrypt(string $data, array $context = []): array
    {
        $operationId = $this->monitor->startOperation('encrypt');
        
        try {
            // Generate IV
            $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
            
            // Get encryption key
            $key = $this->keyManager->getActiveKey();
            
            // Add context to AAD
            $aad = $this->prepareAAD($context);
            
            // Encrypt data
            $tag = '';
            $encrypted = openssl_encrypt(
                $data,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $aad,
                self::TAG_LENGTH
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption failed');
            }

            return [
                'data' => base64_encode($encrypted),
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'key_id' => $key->getId()
            ];
            
        } catch (\Throwable $e) {
            $this->handleEncryptionFailure($e);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    public function decrypt(array $encryptedData, array $context = []): string
    {
        $operationId = $this->monitor->startOperation('decrypt');
        
        try {
            // Validate encrypted data
            $this->validateEncryptedData($encryptedData);
            
            // Get decryption key
            $key = $this->keyManager->getKey($encryptedData['key_id']);
            
            // Add context to AAD
            $aad = $this->prepareAAD($context);
            
            // Decrypt data
            $decrypted = openssl_decrypt(
                base64_decode($encryptedData['data']),
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                base64_decode($encryptedData['iv']),
                base64_decode($encryptedData['tag']),
                $aad
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            return $decrypted;
            
        } catch (\Throwable $e) {
            $this->handleEncryptionFailure($e);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function validateEncryptedData(array $data): void
    {
        $required = ['data', 'iv', 'tag', 'key_id'];
        
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new EncryptionException("Missing required field: {$field}");
            }
        }

        if (!$this->keyManager->keyExists($data['key_id'])) {
            throw new EncryptionException('Invalid key ID');
        }
    }

    private function prepareAAD(array $context): string
    {
        return json_encode([
            'timestamp' => time(),
            'request_id' => request()->id(),
            'context' => $context
        ]);
    }

    private function handleEncryptionFailure(\Throwable $e): void
    {
        $this->monitor->recordFailure('encryption', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class KeyManager
{
    private string $masterKey;
    private array $keyCache = [];
    private KeyRepository $repository;
    private MonitoringService $monitor;

    public function getActiveKey(): EncryptionKey
    {
        $operationId = $this->monitor->startOperation('key_retrieval');
        
        try {
            $activeKey = $this->repository->getActiveKey();
            
            if (!$activeKey) {
                $activeKey = $this->generateNewKey();
            }
            
            return $this->unwrapKey($activeKey);
            
        } catch (\Throwable $e) {
            $this->handleKeyFailure($e);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    public function getKey(string $keyId): EncryptionKey
    {
        if (isset($this->keyCache[$keyId])) {
            return $this->keyCache[$keyId];
        }

        $key = $this->repository->findKey($keyId);
        
        if (!$key) {
            throw new KeyNotFoundException("Key not found: {$keyId}");
        }

        $unwrappedKey = $this->unwrapKey($key);
        $this->keyCache[$keyId] = $unwrappedKey;
        
        return $unwrappedKey;
    }

    public function keyExists(string $keyId): bool
    {
        return isset($this->keyCache[$keyId]) || 
               $this->repository->keyExists($keyId);
    }

    private function generateNewKey(): EncryptionKey
    {
        $keyData = random_bytes(32);
        $keyId = bin2hex(random_bytes(16));
        
        $wrappedKey = $this->wrapKey($keyData);
        
        $this->repository->storeKey(new EncryptionKey(
            $keyId,
            $wrappedKey,
            now()
        ));

        return new EncryptionKey($keyId, $keyData, now());
    }

    private function wrapKey(string $key): string
    {
        $iv = random_bytes(openssl_cipher_iv_length('aes-256-gcm'));
        $tag = '';
        
        $wrapped = openssl_encrypt(
            $key,
            'aes-256-gcm',
            $this->masterKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return base64_encode($iv . $tag . $wrapped);
    }

    private function unwrapKey(EncryptionKey $key): EncryptionKey
    {
        $data = base64_decode($key->getWrappedKey());
        
        $ivLength = openssl_cipher_iv_length('aes-256-gcm');
        $tagLength = 16;
        
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, $tagLength);
        $wrapped = substr($data, $ivLength + $tagLength);
        
        $unwrapped = openssl_decrypt(
            $wrapped,
            'aes-256-gcm',
            $this->masterKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($unwrapped === false) {
            throw new KeyUnwrapException('Failed to unwrap key');
        }

        return new EncryptionKey(
            $key->getId(),
            $unwrapped,
            $key->getCreatedAt()
        );
    }

    private function handleKeyFailure(\Throwable $e): void
    {
        $this->monitor->recordFailure('key_management', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
```
