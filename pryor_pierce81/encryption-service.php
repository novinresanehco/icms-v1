<?php

namespace App\Core\Security;

use App\Core\Contracts\EncryptionInterface;
use App\Core\Exceptions\{EncryptionException, SecurityException};

class EncryptionService implements EncryptionInterface
{
    private KeyManager $keyManager;
    private AuditLogger $auditLogger;
    private SecurityContext $context;
    private array $config;

    public function encrypt(mixed $data, array $context = []): string
    {
        try {
            $this->validateContext();
            $this->validateInput($data);
            
            $key = $this->keyManager->getActiveKey();
            $iv = random_bytes(16);
            
            $encrypted = openssl_encrypt(
                $this->prepareData($data),
                'aes-256-gcm',
                $key->getSecret(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $this->getAad($context),
                16
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption failed');
            }

            $package = $this->packageData($encrypted, $iv, $tag, $key->getId());
            $this->auditLogger->logEncryption($key->getId());
            
            return $package;
            
        } catch (\Throwable $e) {
            $this->handleEncryptionFailure($e, 'encrypt');
            throw $e;
        }
    }

    public function decrypt(string $package, array $context = []): mixed
    {
        try {
            $this->validateContext();
            
            ['data' => $encrypted, 'iv' => $iv, 'tag' => $tag, 'kid' => $keyId] = 
                $this->unpackageData($package);
            
            $key = $this->keyManager->getKey($keyId);
            
            $decrypted = openssl_decrypt(
                $encrypted,
                'aes-256-gcm',
                $key->getSecret(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $this->getAad($context),
                16
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            $this->auditLogger->logDecryption($keyId);
            
            return $this->restoreData($decrypted);
            
        } catch (\Throwable $e) {
            $this->handleEncryptionFailure($e, 'decrypt');
            throw $e;
        }
    }

    public function rotateKeys(): void
    {
        try {
            $this->validateContext();
            
            DB::beginTransaction();
            
            $newKey = $this->keyManager->generateKey();
            $this->reEncryptData($newKey);
            $this->keyManager->setActiveKey($newKey);
            
            DB::commit();
            
            $this->auditLogger->logKeyRotation($newKey->getId());
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleEncryptionFailure($e, 'key_rotation');
            throw $e;
        }
    }

    private function validateContext(): void
    {
        if (!$this->context->isValid()) {
            throw new SecurityException('Invalid security context');
        }

        if ($this->detectAnomalous()) {
            throw new SecurityException('Anomalous encryption activity');
        }
    }

    private function validateInput($data): void
    {
        if ($data === null) {
            throw new EncryptionException('Data cannot be null');
        }

        $serialized = $this->prepareData($data);
        if (strlen($serialized) > $this->config['max_data_size']) {
            throw new EncryptionException('Data size exceeds limit');
        }
    }

    private function prepareData($data): string
    {
        if (is_string($data)) {
            return $data;
        }
        return serialize($data);
    }

    private function restoreData(string $data): mixed
    {
        if ($this->isSerialized($data)) {
            return unserialize($data);
        }
        return $data;
    }

    private function packageData(string $encrypted, string $iv, string $tag, string $keyId): string
    {
        $package = [
            'v' => 1,
            'kid' => $keyId,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($encrypted)
        ];
        
        return json_encode($package);
    }

    private function unpackageData(string $package): array
    {
        $data = json_decode($package, true);
        
        if (!$this->isValidPackage($data)) {
            throw new EncryptionException('Invalid encryption package');
        }
        
        return [
            'data' => base64_decode($data['data']),
            'iv' => base64_decode($data['iv']),
            'tag' => base64_decode($data['tag']),
            'kid' => $data['kid']
        ];
    }

    private function isValidPackage($data): bool
    {
        return isset($data['v'], $data['kid'], $data['iv'], $data['tag'], $data['data']) &&
               $data['v'] === 1 &&
               is_string($data['kid']) &&
               is_string($data['iv']) &&
               is_string($data['tag']) &&
               is_string($data['data']);
    }

    private function getAad(array $context): string
    {
        return hash_hmac(
            'sha256',
            json_encode($context),
            $this->config['aad_key'],
            true
        );
    }

    private function detectAnomalous(): bool
    {
        $key = "encryption_ops_{$this->context->getUserId()}";
        $limit = $this->config['rate_limit'] ?? 1000;
        $window = 3600;
        
        $current = Cache::increment($key);
        
        if ($current === 1) {
            Cache::put($key, 1, $window);
        }
        
        return $current > $limit;
    }

    private function handleEncryptionFailure(\Throwable $e, string $operation): void
    {
        $this->auditLogger->logEncryptionFailure([
            'operation' => $operation,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function isSerialized(string $data): bool
    {
        return @unserialize($data) !== false || $data === 'b:0;';
    }
}
