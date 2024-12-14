<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Services\{ValidationService, KeyManager};
use App\Core\Models\EncryptionKey;
use App\Core\Exceptions\{EncryptionException, SecurityException};
use ParagonIE\Halite\{KeyFactory, Symmetric\AuthenticationKey, Symmetric\EncryptionKey as HaliteKey};

class DataProtectionService
{
    private ValidationService $validator;
    private KeyManager $keyManager;
    private string $masterKey;
    private array $activeKeys = [];

    private const KEY_ROTATION_INTERVAL = 86400; // 24 hours
    private const CACHE_TTL = 3600;
    private const CIPHER = 'aes-256-gcm';

    public function __construct(
        ValidationService $validator,
        KeyManager $keyManager,
        string $masterKey
    ) {
        $this->validator = $validator;
        $this->keyManager = $keyManager;
        $this->masterKey = $masterKey;
    }

    public function encrypt(string $data, string $context = 'default'): array
    {
        try {
            $this->validateInput($data);
            $key = $this->getEncryptionKey($context);
            
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_aes256gcm_encrypt(
                $data,
                $nonce,
                $nonce,
                $key
            );

            $mac = hash_hmac('sha256', $ciphertext, $key);

            return [
                'data' => base64_encode($ciphertext),
                'nonce' => base64_encode($nonce),
                'mac' => $mac,
                'key_id' => $this->keyManager->getCurrentKeyId($context),
                'context' => $context
            ];

        } catch (\Exception $e) {
            $this->handleEncryptionError($e);
            throw new EncryptionException('Encryption failed');
        }
    }

    public function decrypt(array $encryptedData): string
    {
        try {
            $this->validateEncryptedData($encryptedData);
            
            $key = $this->getKeyById($encryptedData['key_id']);
            $ciphertext = base64_decode($encryptedData['data']);
            $nonce = base64_decode($encryptedData['nonce']);

            // Verify MAC
            $computedMac = hash_hmac('sha256', $ciphertext, $key);
            if (!hash_equals($computedMac, $encryptedData['mac'])) {
                throw new SecurityException('Data integrity verification failed');
            }

            $plaintext = sodium_crypto_aead_aes256gcm_decrypt(
                $ciphertext,
                $nonce,
                $nonce,
                $key
            );

            if ($plaintext === false) {
                throw new SecurityException('Decryption failed');
            }

            return $plaintext;

        } catch (\Exception $e) {
            $this->handleDecryptionError($e, $encryptedData);
            throw new EncryptionException('Decryption failed');
        }
    }

    public function reencrypt(array $encryptedData, string $newContext = null): array
    {
        return $this->encrypt(
            $this->decrypt($encryptedData),
            $newContext ?? $encryptedData['context']
        );
    }

    public function rotateKeys(): void
    {
        DB::beginTransaction();
        try {
            foreach ($this->keyManager->getActiveContexts() as $context) {
                $this->rotateContextKey($context);
            }
            
            $this->keyManager->archiveOldKeys();
            $this->clearKeyCache();
            
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw new EncryptionException('Key rotation failed: ' . $e->getMessage());
        }
    }

    public function verifyIntegrity(array $encryptedData): bool
    {
        try {
            $this->validateEncryptedData($encryptedData);
            
            $key = $this->getKeyById($encryptedData['key_id']);
            $ciphertext = base64_decode($encryptedData['data']);
            
            $computedMac = hash_hmac('sha256', $ciphertext, $key);
            return hash_equals($computedMac, $encryptedData['mac']);

        } catch (\Exception $e) {
            Log::error('Integrity verification failed', [
                'error' => $e->getMessage(),
                'key_id' => $encryptedData['key_id'] ?? null
            ]);
            return false;
        }
    }

    protected function validateInput(string $data): void
    {
        if (empty($data)) {
            throw new EncryptionException('Empty data provided for encryption');
        }

        if (strlen($data) > $this->keyManager->getMaxDataSize()) {
            throw new EncryptionException('Data exceeds maximum allowed size');
        }
    }

    protected function validateEncryptedData(array $data): void
    {
        $requiredFields = ['data', 'nonce', 'mac', 'key_id', 'context'];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new EncryptionException("Missing required field: {$field}");
            }
        }

        if (!$this->keyManager->isValidKeyId($data['key_id'])) {
            throw new SecurityException('Invalid encryption key ID');
        }
    }

    protected function getEncryptionKey(string $context): string
    {
        return Cache::remember(
            "encryption_key.{$context}",
            self::CACHE_TTL,
            function () use ($context) {
                return $this->keyManager->getCurrentKey($context);
            }
        );
    }

    protected function getKeyById(string $keyId): string
    {
        return Cache::remember(
            "encryption_key.{$keyId}",
            self::CACHE_TTL,
            function () use ($keyId) {
                return $this->keyManager->getKeyById($keyId);
            }
        );
    }

    protected function rotateContextKey(string $context): void
    {
        $newKey = $this->keyManager->generateKey();
        $keyId = $this->keyManager->storeKey($newKey, $context);
        
        Cache::put(
            "encryption_key.{$context}",
            $newKey,
            self::CACHE_TTL
        );

        Log::info('Key rotated for context', [
            'context' => $context,
            'key_id' => $keyId
        ]);
    }

    protected function clearKeyCache(): void
    {
        Cache::tags(['encryption_keys'])->flush();
    }

    protected function handleEncryptionError(\Exception $e): void
    {
        Log::error('Encryption error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleDecryptionError(\Exception $e, array $data): void
    {
        Log::error('Decryption error', [
            'error' => $e->getMessage(),
            'key_id' => $data['key_id'] ?? null,
            'context' => $data['context'] ?? null
        ]);
    }
}
