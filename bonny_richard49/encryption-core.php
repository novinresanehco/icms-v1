<?php

namespace App\Core\Encryption;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Audit\AuditSystem;
use Illuminate\Support\Facades\Redis;

class EncryptionSystem implements EncryptionInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private AuditSystem $audit;
    private array $config;
    private array $activeKeys = [];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        AuditSystem $audit,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function encrypt(string $data, array $context = []): string 
    {
        $keyId = $this->getActiveKeyId();
        $key = $this->getEncryptionKey($keyId);

        try {
            $iv = random_bytes(16);
            $encryptedData = openssl_encrypt(
                $data,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($encryptedData === false) {
                throw new EncryptionException('Encryption failed');
            }

            $metadata = $this->generateMetadata($keyId, $context);
            
            $package = $this->packEncryptedData($encryptedData, $iv, $tag, $metadata);
            
            $this->auditEncryption('encrypt', $metadata);
            
            return $package;
            
        } catch (\Throwable $e) {
            $this->handleEncryptionFailure($e, 'encrypt', $context);
            throw $e;
        }
    }

    public function decrypt(string $package, array $context = []): string
    {
        try {
            [$encryptedData, $iv, $tag, $metadata] = $this->unpackEncryptedData($package);
            
            $this->validateMetadata($metadata, $context);
            
            $key = $this->getEncryptionKey($metadata['key_id']);
            
            $decryptedData = openssl_decrypt(
                $encryptedData,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decryptedData === false) {
                throw new EncryptionException('Decryption failed');
            }

            $this->auditEncryption('decrypt', $metadata);
            
            return $decryptedData;
            
        } catch (\Throwable $e) {
            $this->handleEncryptionFailure($e, 'decrypt', $context);
            throw $e;
        }
    }

    public function rotateKeys(): void
    {
        $this->security->validateOperation('encryption.rotate_keys');

        try {
            $newKeyId = $this->generateKeyId();
            $newKey = $this->generateEncryptionKey();
            
            Redis::multi();
            
            // Store new key
            Redis::hset(
                'encryption:keys',
                $newKeyId,
                $this->encryptKeyForStorage($newKey)
            );
            
            // Update active key
            Redis::set('encryption:active_key', $newKeyId);
            
            // Set expiry on old keys
            $this->expireOldKeys();
            
            Redis::exec();
            
            $this->audit->logAction('encryption.key_rotation', [
                'key_id' => $newKeyId,
                'timestamp' => time()
            ]);
            
        } catch (\Throwable $e) {
            $this->handleEncryptionFailure($e, 'key_rotation');
            throw $e;
        }
    }

    private function getActiveKeyId(): string
    {
        return $this->cache->remember(
            'encryption:active_key',
            3600,
            function() {
                $keyId = Redis::get('encryption:active_key');
                if (!$keyId) {
                    throw new EncryptionException('No active encryption key found');
                }
                return $keyId;
            }
        );
    }

    private function getEncryptionKey(string $keyId): string
    {
        if (isset($this->activeKeys[$keyId])) {
            return $this->activeKeys[$keyId];
        }

        $encryptedKey = Redis::hget('encryption:keys', $keyId);
        if (!$encryptedKey) {
            throw new EncryptionException("Encryption key not found: {$keyId}");
        }

        $key = $this->decryptKeyFromStorage($encryptedKey);
        $this->activeKeys[$keyId] = $key;
        
        return $key;
    }

    private function generateEncryptionKey(): string
    {
        return random_bytes(32);
    }

    private function generateKeyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function encryptKeyForStorage(string $key): string
    {
        $masterKey = $this->getMasterKey();
        
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt(
            $key,
            'aes-256-gcm',
            $masterKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return base64_encode($iv . $tag . $encrypted);
    }

    private function decryptKeyFromStorage(string $encryptedKey): string
    {
        $masterKey = $this->getMasterKey();
        
        $data = base64_decode($encryptedKey);
        $iv = substr($data, 0, 16);
        $tag = substr($data, 16, 16);
        $encrypted = substr($data, 32);

        $key = openssl_decrypt(
            $encrypted,
            'aes-256-gcm',
            $masterKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($key === false) {
            throw new EncryptionException('Failed to decrypt storage key');
        }

        return $key;
    }

    private function getMasterKey(): string
    {
        $key = getenv('MASTER_ENCRYPTION_KEY');
        if (!$key) {
            throw new EncryptionException('Master encryption key not found');
        }
        return base64_decode($key);
    }

    private function generateMetadata(string $keyId, array $context): array
    {
        return [
            'key_id' => $keyId,
            'timestamp' => time(),
            'context' => $context,
            'version' => $this->config['encryption_version']
        ];
    }

    private function validateMetadata(array $metadata, array $context): void
    {
        if ($metadata['version'] !== $this->config['encryption_version']) {
            throw new EncryptionException('Incompatible encryption version');
        }

        if (isset($context['required_key_id']) && 
            $metadata['key_id'] !== $context['required_key_id']) {
            throw new EncryptionException('Key ID mismatch');
        }
    }

    private function packEncryptedData(
        string $encryptedData,
        string $iv,
        string $tag,
        array $metadata
    ): string {
        $package = [
            'data' => base64_encode($encryptedData),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'metadata' => $metadata
        ];

        return base64_encode(json_encode($package));
    }

    private function unpackEncryptedData(string $package): array
    {
        $decoded = json_decode(base64_decode($package), true);
        if (!$decoded) {
            throw new EncryptionException('Invalid encryption package');
        }

        return [
            base64_decode($decoded['data']),
            base64_decode($decoded['iv']),
            base64_decode($decoded['tag']),
            $decoded['metadata']
        ];
    }

    private function expireOldKeys(): void
    {
        $activeKeyId = $this->getActiveKeyId();
        $keys = Redis::hkeys('encryption:keys');
        
        foreach ($keys as $keyId) {
            if ($keyId !== $activeKeyId) {
                Redis::hset(
                    'encryption:key_expiry',
                    $keyId,
                    time() + $this->config['key_expiry_time']
                );
            }
        }
    }

    private function auditEncryption(string $operation, array $metadata): void
    {
        $this->audit->logAction("encryption.{$operation}", [
            'key_id' => $metadata['key_id'],
            'timestamp' => $metadata['timestamp'],
            'context' => $metadata['context']
        ]);
    }

    private function handleEncryptionFailure(\Throwable $e, string $operation, array $context = []): void
    {
        $this->audit->logAction('encryption.failure', [
            'operation' => $operation,
            'error' => $e->getMessage(),
            'context' => $context
        ]);

        if ($this->isSystemFailure($e)) {
            $this->security->triggerAlert('encryption_system_failure', [
                'operation' => $operation,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function isSystemFailure(\Throwable $e): bool
    {
        return $e instanceof \OpenSSLException || 
               $e instanceof \RuntimeException ||
               str_contains($e->getMessage(), 'OpenSSL error');
    }
}
