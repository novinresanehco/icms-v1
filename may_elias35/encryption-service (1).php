<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Events\SecurityEvent;
use App\Core\Exceptions\{
    EncryptionException,
    SecurityException,
    KeyManagementException
};
use ParagonIE\Halite\{
    KeyFactory,
    Symmetric\Crypto as SymmetricCrypto,
    Symmetric\EncryptionKey
};

class EncryptionService 
{
    protected SecurityManager $security;
    protected array $config;
    protected array $activeKeys = [];
    protected string $masterKeyId;

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
        $this->config = config('security.encryption');
        $this->initializeKeySystem();
    }

    public function encrypt(string $data, array $context = []): array
    {
        $keyId = $this->getActiveKeyId($context);
        
        try {
            $this->validateEncryptOperation($data, $context);
            
            $key = $this->getEncryptionKey($keyId);
            
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = SymmetricCrypto::encrypt(
                $data,
                $key,
                $nonce
            );

            $this->logEncryptOperation($keyId, $context);

            return [
                'ciphertext' => base64_encode($ciphertext),
                'key_id' => $keyId,
                'nonce' => base64_encode($nonce),
                'algorithm' => 'XChaCha20-Poly1305',
                'timestamp' => time()
            ];

        } catch (\Exception $e) {
            $this->handleEncryptionException($e, 'encrypt', $context);
            throw new EncryptionException('Encryption failed: ' . $e->getMessage());
        }
    }

    public function decrypt(array $encryptedData, array $context = []): string
    {
        try {
            $this->validateDecryptOperation($encryptedData, $context);
            
            $key = $this->getEncryptionKey($encryptedData['key_id']);
            
            $ciphertext = base64_decode($encryptedData['ciphertext']);
            $nonce = base64_decode($encryptedData['nonce']);

            $plaintext = SymmetricCrypto::decrypt(
                $ciphertext,
                $key,
                $nonce
            );

            $this->logDecryptOperation($encryptedData['key_id'], $context);

            return $plaintext;

        } catch (\Exception $e) {
            $this->handleEncryptionException($e, 'decrypt', $context);
            throw new EncryptionException('Decryption failed: ' . $e->getMessage());
        }
    }

    public function rotateKeys(): void
    {
        try {
            $this->validateKeyRotation();
            
            $newKeyId = $this->generateKeyId();
            $newKey = KeyFactory::generateEncryptionKey();

            $this->storeKey($newKeyId, $newKey);
            $this->updateActiveKeys($newKeyId);
            $this->scheduleKeyDeletion($this->getOldestKeyId());

            $this->logKeyRotation($newKeyId);

        } catch (\Exception $e) {
            $this->handleKeyManagementException($e);
            throw new KeyManagementException('Key rotation failed: ' . $e->getMessage());
        }
    }

    public function reEncrypt(array $encryptedData, array $context = []): array
    {
        try {
            $plaintext = $this->decrypt($encryptedData, $context);
            return $this->encrypt($plaintext, $context);

        } catch (\Exception $e) {
            $this->handleEncryptionException($e, 're-encrypt', $context);
            throw new EncryptionException('Re-encryption failed: ' . $e->getMessage());
        }
    }

    protected function initializeKeySystem(): void
    {
        if (!$this->hasInitializedKeys()) {
            $this->generateInitialKeys();
        }
        
        $this->loadActiveKeys();
        $this->validateKeySystem();
    }

    protected function generateInitialKeys(): void
    {
        $masterKeyId = $this->generateKeyId();
        $masterKey = KeyFactory::generateEncryptionKey();
        
        $this->storeKey($masterKeyId, $masterKey);
        $this->setMasterKeyId($masterKeyId);
        
        $this->logKeyGeneration($masterKeyId, 'master');
    }

    protected function loadActiveKeys(): void
    {
        $this->activeKeys = Cache::tags('encryption_keys')
            ->remember('active_keys', 3600, function () {
                return $this->fetchActiveKeysFromStorage();
            });
    }

    protected function validateKeySystem(): void
    {
        if (empty($this->activeKeys)) {
            throw new KeyManagementException('No active encryption keys found');
        }

        if (!$this->masterKeyId) {
            throw new KeyManagementException('Master key ID not set');
        }

        foreach ($this->activeKeys as $keyId => $key) {
            if (!$this->validateKey($key)) {
                throw new KeyManagementException("Invalid key detected: {$keyId}");
            }
        }
    }

    protected function getActiveKeyId(array $context = []): string
    {
        if (isset($context['key_id']) && $this->isKeyActive($context['key_id'])) {
            return $context['key_id'];
        }
        
        return $this->getLatestKeyId();
    }

    protected function getEncryptionKey(string $keyId): EncryptionKey
    {
        if (!$this->isKeyActive($keyId)) {
            throw new KeyManagementException("Key not active: {$keyId}");
        }

        return $this->loadKey($keyId);
    }

    protected function generateKeyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function validateKey(EncryptionKey $key): bool
    {
        try {
            $testData = random_bytes(32);
            $encrypted = SymmetricCrypto::encrypt($testData, $key);
            $decrypted = SymmetricCrypto::decrypt($encrypted, $key);
            
            return $testData === $decrypted;
            
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function storeKey(string $keyId, EncryptionKey $key): void
    {
        // Implementation depends on key storage mechanism
    }

    protected function loadKey(string $keyId): EncryptionKey
    {
        // Implementation depends on key storage mechanism
        return $this->activeKeys[$keyId];
    }

    protected function updateActiveKeys(string $newKeyId): void
    {
        $this->activeKeys[$newKeyId] = $this->loadKey($newKeyId);
        Cache::tags('encryption_keys')->flush();
    }

    protected function scheduleKeyDeletion(string $keyId): void
    {
        // Implementation depends on key deletion policy
    }

    protected function validateEncryptOperation(string $data, array $context): void
    {
        if (empty($data)) {
            throw new EncryptionException('Empty data provided for encryption');
        }

        if ($this->exceedsMaxSize($data)) {
            throw new EncryptionException('Data exceeds maximum encryption size');
        }
    }

    protected function validateDecryptOperation(array $encryptedData, array $context): void
    {
        $required = ['ciphertext', 'key_id', 'nonce', 'algorithm', 'timestamp'];
        
        foreach ($required as $field) {
            if (!isset($encryptedData[$field])) {
                throw new EncryptionException("Missing required field: {$field}");
            }
        }

        if ($encryptedData['algorithm'] !== 'XChaCha20-Poly1305') {
            throw new EncryptionException('Unsupported encryption algorithm');
        }
    }

    protected function validateKeyRotation(): void
    {
        if (count($this->activeKeys) >= $this->config['max_active_keys']) {
            throw new KeyManagementException('Maximum number of active keys reached');
        }
    }

    protected function exceedsMaxSize(string $data): bool
    {
        return strlen($data) > $this->config['max_plaintext_size'];
    }

    protected function isKeyActive(string $keyId): bool
    {
        return isset($this->activeKeys[$keyId]);
    }

    protected function getLatestKeyId(): string
    {
        return array_key_last($this->activeKeys);
    }

    protected function getOldestKeyId(): string
    {
        return array_key_first($this->activeKeys);
    }

    protected function hasInitializedKeys(): bool
    {
        return !empty($this->fetchActiveKeysFromStorage());
    }

    protected function fetchActiveKeysFromStorage(): array
    {
        // Implementation depends on key storage mechanism
        return [];
    }

    protected function setMasterKeyId(string $keyId): void
    {
        $this->masterKeyId = $keyId;
        // Persist master key ID
    }

    protected function logEncryptOperation(string $keyId, array $context): void
    {
        event(new SecurityEvent('encryption', [
            'operation' => 'encrypt',
            'key_id' => $keyId,
            'context' => $context
        ]));
    }

    protected function logDecryptOperation(string $keyId, array $context): void
    {
        event(new SecurityEvent('encryption', [
            'operation' => 'decrypt',
            'key_id' => $keyId,
            'context' => $context
        ]));
    }

    protected function logKeyGeneration(string $keyId, string $type): void
    {
        event(new SecurityEvent('key_management', [
            'operation' => 'generate',
            'key_id' => $keyId,
            'type' => $type
        ]));
    }

    protected function logKeyRotation(string $newKeyId): void
    {
        event(new SecurityEvent('key_management', [
            'operation' => 'rotate',
            'new_key_id' => $newKeyId,
            'active_keys' => count($this->activeKeys)
        ]));
    }

    protected function handleEncryptionException(\Exception $e, string $operation, array $context): void
    {
        Log::error('Encryption operation failed', [
            'operation' => $operation,
            'context' => $context,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleKeyManagementException(\Exception $e): void
    {
        Log::error('Key management operation failed', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
