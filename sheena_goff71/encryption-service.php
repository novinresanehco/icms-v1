<?php

namespace App\Core\Security\Services;

use App\Core\Interfaces\EncryptionInterface;
use App\Core\Security\Models\{EncryptionKey, EncryptionResult};
use App\Core\Events\KeyRotationEvent;
use App\Core\Exceptions\EncryptionException;
use Illuminate\Support\Facades\{Cache, Event};
use ParagonIE\Halite\{KeyFactory, Symmetric\Crypto as SymmetricCrypto};
use ParagonIE\HiddenString\HiddenString;

class EncryptionService implements EncryptionInterface
{
    private string $masterKey;
    private array $activeKeys = [];
    private KeyRotationManager $keyManager;
    private AuditService $auditService;
    private int $keyRotationInterval;

    public function __construct(
        KeyRotationManager $keyManager,
        AuditService $auditService,
        string $masterKey,
        int $keyRotationInterval = 86400
    ) {
        $this->keyManager = $keyManager;
        $this->auditService = $auditService;
        $this->masterKey = $masterKey;
        $this->keyRotationInterval = $keyRotationInterval;
    }

    public function encrypt(string $data, array $context = []): EncryptionResult
    {
        try {
            $key = $this->getActiveKey();
            
            $hiddenData = new HiddenString($data);
            $encryptedData = SymmetricCrypto::encrypt(
                $hiddenData,
                $key->getEncryptionKey()
            );
            
            $hash = hash_hmac(
                'sha256',
                $encryptedData,
                $key->getHashKey()
            );
            
            $this->auditService->logOperation(
                'data_encryption',
                array_merge($context, ['key_id' => $key->getId()])
            );
            
            return new EncryptionResult(
                $encryptedData,
                $hash,
                $key->getId()
            );

        } catch (\Throwable $e) {
            $this->handleEncryptionFailure('encryption_failed', $e);
            throw new EncryptionException('Encryption failed', 0, $e);
        }
    }

    public function decrypt(string $encryptedData, string $keyId): string
    {
        try {
            $key = $this->getKeyById($keyId);
            
            $decryptedData = SymmetricCrypto::decrypt(
                $encryptedData,
                $key->getEncryptionKey()
            );
            
            $this->auditService->logOperation(
                'data_decryption',
                ['key_id' => $keyId]
            );
            
            return $decryptedData->getString();

        } catch (\Throwable $e) {
            $this->handleEncryptionFailure('decryption_failed', $e);
            throw new EncryptionException('Decryption failed', 0, $e);
        }
    }

    public function verifyIntegrity(string $data, string $hash, string $keyId): bool
    {
        try {
            $key = $this->getKeyById($keyId);
            
            $computedHash = hash_hmac(
                'sha256',
                $data,
                $key->getHashKey()
            );
            
            $isValid = hash_equals($hash, $computedHash);
            
            $this->auditService->logOperation(
                'integrity_check',
                [
                    'key_id' => $keyId,
                    'result' => $isValid
                ]
            );
            
            return $isValid;

        } catch (\Throwable $e) {
            $this->handleEncryptionFailure('integrity_check_failed', $e);
            return false;
        }
    }

    private function getActiveKey(): EncryptionKey
    {
        $cacheKey = 'encryption:active_key';
        
        if ($cachedKey = Cache::get($cacheKey)) {
            return $cachedKey;
        }
        
        if ($this->shouldRotateKeys()) {
            return $this->rotateKeys();
        }
        
        $key = $this->keyManager->getActiveKey();
        Cache::put($cacheKey, $key, now()->addMinutes(5));
        
        return $key;
    }

    private function getKeyById(string $keyId): EncryptionKey
    {
        $cacheKey = "encryption:key:{$keyId}";
        
        return Cache::remember(
            $cacheKey,
            now()->addHours(1),
            fn() => $this->keyManager->getKeyById($keyId)
        );
    }

    private function shouldRotateKeys(): bool
    {
        $lastRotation = Cache::get('encryption:last_rotation');
        return !$lastRotation || 
            (time() - $lastRotation) >= $this->keyRotationInterval;
    }

    private function rotateKeys(): EncryptionKey
    {
        try {
            $newKey = $this->keyManager->rotateKeys();
            
            Cache::put('encryption:last_rotation', time(), now()->addDay());
            Cache::put('encryption:active_key', $newKey, now()->addMinutes(5));
            
            Event::dispatch(new KeyRotationEvent($newKey->getId()));
            
            $this->auditService->logOperation('key_rotation', [
                'key_id' => $newKey->getId()
            ]);
            
            return $newKey;

        } catch (\Throwable $e) {
            $this->handleEncryptionFailure('key_rotation_failed', $e);
            throw new EncryptionException('Key rotation failed', 0, $e);
        }
    }

    private function handleEncryptionFailure(string $type, \Throwable $e): void
    {
        $this->auditService->logFailure($type, $e, [
            'service' => 'encryption'
        ]);
    }
}
