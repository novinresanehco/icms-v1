<?php

namespace App\Core\Security;

use App\Core\Monitoring\SystemMonitor;

class EncryptionManager implements EncryptionInterface
{
    private SystemMonitor $monitor;
    private array $config;
    private array $activeKeys = [];

    private const CIPHER = 'aes-256-gcm';
    private const KEY_LENGTH = 32;
    private const TAG_LENGTH = 16;
    private const AAD_LENGTH = 64;

    public function __construct(
        SystemMonitor $monitor,
        array $config
    ) {
        $this->monitor = $monitor;
        $this->config = $config;
        $this->initializeKeys();
    }

    public function encrypt(string $data, array $context = []): EncryptedData
    {
        $monitoringId = $this->monitor->startOperation('encryption');
        
        try {
            $key = $this->getActiveKey();
            
            $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
            $aad = random_bytes(self::AAD_LENGTH);
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

            $result = new EncryptedData(
                $encrypted,
                $iv,
                $tag,
                $aad,
                $key['id']
            );

            $this->monitor->recordSuccess($monitoringId);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new EncryptionException('Encryption failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function decrypt(EncryptedData $data): string
    {
        $monitoringId = $this->monitor->startOperation('decryption');
        
        try {
            $key = $this->getKey($data->getKeyId());
            
            $decrypted = openssl_decrypt(
                $data->getData(),
                self::CIPHER,
                $key['value'],
                OPENSSL_RAW_DATA,
                $data->getIv(),
                $data->getTag(),
                $data->getAad()
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            $this->monitor->recordSuccess($monitoringId);
            
            return $decrypted;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new EncryptionException('Decryption failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function initializeKeys(): void
    {
        foreach ($this->config['keys'] as $key) {
            if ($this->validateKey($key)) {
                $this->activeKeys[$key['id']] = $key;
            }
        }

        if (empty($this->activeKeys)) {
            throw new EncryptionException('No valid encryption keys found');
        }
    }

    private function validateKey(array $key): bool
    {
        return isset($key['id']) &&
               isset($key['value']) &&
               strlen(base64_decode($key['value'])) === self::KEY_LENGTH;
    }

    private function getActiveKey(): array
    {
        $activeKeyId = $this->config['active_key_id'];
        return $this->getKey($activeKeyId);
    }

    private function getKey(string $keyId): array
    {
        if (!isset($this->activeKeys[$keyId])) {
            throw new EncryptionException('Invalid key ID');
        }

        return $this->activeKeys[$keyId];
    }
}

class EncryptedData
{
    private string $data;
    private string $iv;
    private string $tag;
    private string $aad;
    private string $keyId;

    public function __construct(
        string $data,
        string $iv,
        string $tag,
        string $aad,
        string $keyId
    ) {
        $this->data = $data;
        $this->iv = $iv;
        $this->tag = $tag;
        $this->aad = $aad;
        $this->keyId = $keyId;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function getIv(): string
    {
        return $this->iv;
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getAad(): string
    {
        return $this->aad;
    }

    public function getKeyId(): string
    {
        return $this->keyId;
    }
}
