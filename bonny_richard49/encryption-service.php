<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\EncryptionException;
use App\Core\Interfaces\EncryptionInterface;

class EncryptionService implements EncryptionInterface 
{
    private string $cipher = 'aes-256-gcm';
    private array $config;
    private string $masterKey;
    
    public function __construct(string $masterKey, array $config)
    {
        $this->masterKey = $masterKey;
        $this->config = $config;
        $this->validateSetup();
    }

    public function encrypt(string $data, ?string $context = null): array
    {
        try {
            $key = $this->getDerivedKey($context);
            $iv = random_bytes(16);
            $tag = '';
            
            $encrypted = openssl_encrypt(
                $data,
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption failed');
            }

            return [
                'data' => base64_encode($encrypted),
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'key_id' => $this->getCurrentKeyId($context)
            ];
        } catch (\Exception $e) {
            throw new EncryptionException('Encryption failed: ' . $e->getMessage());
        }
    }

    public function decrypt(array $encryptedData, ?string $context = null): string
    {
        try {
            $this->validateEncryptedData($encryptedData);
            
            $key = $this->getKeyForId($encryptedData['key_id'], $context);
            $encrypted = base64_decode($encryptedData['data']);
            $iv = base64_decode($encryptedData['iv']);
            $tag = base64_decode($encryptedData['tag']);

            $decrypted = openssl_decrypt(
                $encrypted,
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            return $decrypted;
        } catch (\Exception $e) {
            throw new EncryptionException('Decryption failed: ' . $e->getMessage());
        }
    }

    public function rotateKeys(?string $context = null): void
    {
        try {
            $newKeyId = $this->generateKeyId();
            $newKey = $this->generateKey();
            
            $this->storeKey($newKeyId, $newKey, $context);
            $this->updateCurrentKeyId($newKeyId, $context);
            
            // Keep last 2 keys for decryption of existing data
            $this->cleanupOldKeys($context);
        } catch (\Exception $e) {
            throw new EncryptionException('Key rotation failed: ' . $e->getMessage());
        }
    }

    public function verifyIntegrity(array $data): bool
    {
        try {
            if (!isset($data['hash'])) {
                return false;
            }

            $actualHash = $this->calculateHash(
                array_diff_key($data, ['hash' => true])
            );

            return hash_equals($data['hash'], $actualHash);
        } catch (\Exception $e) {
            throw new EncryptionException('Integrity verification failed: ' . $e->getMessage());
        }
    }

    protected function validateSetup(): void
    {
        if (empty($this->masterKey)) {
            throw new EncryptionException('Master key not configured');
        }

        if (!in_array($this->cipher, openssl_get_cipher_methods())) {
            throw new EncryptionException('Unsupported cipher method');
        }
    }

    protected function getDerivedKey(?string $context): string
    {
        $keyId = $this->getCurrentKeyId($context);
        return $this->getKeyForId($keyId, $context);
    }

    protected function getCurrentKeyId(?string $context): string
    {
        $cacheKey = $this->getKeyCacheKey('current', $context);
        
        return Cache::remember(
            $cacheKey,
            $this->config['key_cache_ttl'] ?? 3600,
            function() use ($context) {
                $keyId = $this->generateKeyId();
                $this->storeKey($keyId, $this->generateKey(), $context);
                return $keyId;
            }
        );
    }

    protected function getKeyForId(string $keyId, ?string $context): string
    {
        $cacheKey = $this->getKeyCacheKey($keyId, $context);
        $key = Cache::get($cacheKey);
        
        if ($key === null) {
            throw new EncryptionException('Encryption key not found');
        }
        
        return $key;
    }

    protected function generateKey(): string
    {
        return random_bytes(32);
    }

    protected function generateKeyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function storeKey(string $keyId, string $key, ?string $context): void
    {
        $cacheKey = $this->getKeyCacheKey($keyId, $context);
        Cache::put($cacheKey, $key, $this->config['key_lifetime'] ?? 86400);
    }

    protected function updateCurrentKeyId(string $keyId, ?string $context): void
    {
        $cacheKey = $this->getKeyCacheKey('current', $context);
        Cache::put($cacheKey, $keyId, $this->config['key_lifetime'] ?? 86400);
    }

    protected function cleanupOldKeys(?string $context): void
    {
        // Implementation for key cleanup while maintaining required key history
    }

    protected function getKeyCacheKey(string $identifier, ?string $context): string
    {
        $contextHash = $context ? md5($context) : 'global';
        return "encryption:keys:{$contextHash}:{$identifier}";
    }

    protected function calculateHash(array $data): string
    {
        return hash_hmac('sha256', json_encode($data), $this->masterKey);
    }

    protected function validateEncryptedData(array $data): void
    {
        $required = ['data', 'iv', 'tag', 'key_id'];
        
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new EncryptionException("Missing required field: {$field}");
            }
        }
    }
}
