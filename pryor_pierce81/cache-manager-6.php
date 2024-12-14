<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\{CacheException, SecurityException};
use Psr\Log\LoggerInterface;

class CacheManager implements CacheManagerInterface 
{
    private $store;
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        CacheStoreInterface $store,
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->store = $store;
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function get(string $key, array $context = []): mixed
    {
        try {
            $this->validateKey($key);
            $this->security->validateAccess('cache:read', $key, $context);

            $data = $this->store->get($this->getSecureKey($key, $context));
            if ($data === null) {
                return null;
            }

            $this->validateIntegrity($data);
            return $this->decryptData($data);

        } catch (\Exception $e) {
            $this->logger->error('Cache read failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw new CacheException('Cache read failed', 0, $e);
        }
    }

    public function set(string $key, mixed $value, array $context = [], ?int $ttl = null): void
    {
        try {
            $this->validateKey($key);
            $this->security->validateAccess('cache:write', $key, $context);

            $encrypted = $this->encryptData($value);
            $this->store->set(
                $this->getSecureKey($key, $context),
                $encrypted,
                $ttl ?? $this->config['default_ttl']
            );

        } catch (\Exception $e) {
            $this->logger->error('Cache write failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw new CacheException('Cache write failed', 0, $e);
        }
    }

    public function delete(string $key, array $context = []): void
    {
        try {
            $this->validateKey($key);
            $this->security->validateAccess('cache:delete', $key, $context);

            $this->store->delete($this->getSecureKey($key, $context));

        } catch (\Exception $e) {
            $this->logger->error('Cache delete failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw new CacheException('Cache delete failed', 0, $e);
        }
    }

    public function clear(array $context = []): void
    {
        try {
            $this->security->validateAccess('cache:clear', '*', $context);
            $this->store->clear();

        } catch (\Exception $e) {
            $this->logger->error('Cache clear failed', [
                'error' => $e->getMessage()
            ]);
            throw new CacheException('Cache clear failed', 0, $e);
        }
    }

    private function validateKey(string $key): void
    {
        if (strlen($key) > $this->config['max_key_length']) {
            throw new CacheException('Key too long');
        }

        if (!preg_match('/^[a-zA-Z0-9:_\-\.]+$/', $key)) {
            throw new CacheException('Invalid key format');
        }
    }

    private function getSecureKey(string $key, array $context): string
    {
        $userId = $context['user_id'] ?? '';
        return hash_hmac(
            'sha256',
            $key,
            $userId . $this->security->getSessionToken()
        );
    }

    private function validateIntegrity(array $data): void
    {
        if (!isset($data['hash']) || !isset($data['value'])) {
            throw new SecurityException('Invalid cache data structure');
        }

        $computed = hash_hmac(
            'sha256',
            $data['value'],
            $this->security->getEncryptionKey()
        );

        if (!hash_equals($computed, $data['hash'])) {
            throw new SecurityException('Cache data integrity check failed');
        }
    }

    private function encryptData(mixed $value): array
    {
        $serialized = serialize($value);
        $encrypted = openssl_encrypt(
            $serialized,
            $this->config['cipher'],
            $this->security->getEncryptionKey(),
            0,
            random_bytes(16)
        );

        return [
            'value' => $encrypted,
            'hash' => hash_hmac(
                'sha256',
                $encrypted,
                $this->security->getEncryptionKey()
            )
        ];
    }

    private function decryptData(array $data): mixed
    {
        $decrypted = openssl_decrypt(
            $data['value'],
            $this->config['cipher'],
            $this->security->getEncryptionKey(),
            0,
            substr($data['value'], 0, 16)
        );

        return unserialize($decrypted);
    }

    private function getDefaultConfig(): array
    {
        return [
            'default_ttl' => 3600,
            'max_key_length' => 255,
            'cipher' => 'aes-256-gcm'
        ];
    }
}
