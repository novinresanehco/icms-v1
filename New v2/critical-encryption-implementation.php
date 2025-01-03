<?php

namespace App\Core\Encryption;

class EncryptionService implements EncryptionInterface
{
    private string $key;
    private string $cipher;
    private KeyManager $keys;
    private SecurityManager $security;
    private MetricsCollector $metrics;

    public function encrypt($data, array $options = []): string
    {
        $monitorId = $this->metrics->startOperation('encryption');
        
        try {
            // Generate IV
            $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
            
            // Get encryption key
            $key = $this->getEncryptionKey($options);
            
            // Encrypt data
            $encrypted = openssl_encrypt(
                serialize($data),
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            // Add authentication tag
            $tag = $this->generateTag($encrypted, $iv, $key);
            
            // Combine for storage
            $result = base64_encode($iv . $tag . $encrypted);
            
            $this->metrics->recordSuccess($monitorId);
            return $result;
            
        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, $e);
            throw new EncryptionException('Encryption failed', 0, $e);
        }
    }

    public function decrypt(string $data, array $options = []): mixed
    {
        $monitorId = $this->metrics->startOperation('decryption');
        
        try {
            // Decode from storage format
            $data = base64_decode($data);
            
            // Extract components
            $iv = substr($data, 0, openssl_cipher_iv_length($this->cipher));
            $tag = substr($data, openssl_cipher_iv_length($this->cipher), 32);
            $encrypted = substr($data, openssl_cipher_iv_length($this->cipher) + 32);
            
            // Get decryption key
            $key = $this->getEncryptionKey($options);
            
            // Verify authentication tag
            if (!$this->verifyTag($encrypted, $iv, $tag, $key)) {
                throw new SecurityException('Data tampering detected');
            }
            
            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted,
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }
            
            $this->metrics->recordSuccess($monitorId);
            return unserialize($decrypted);
            
        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, $e);
            throw new EncryptionException('Decryption failed', 0, $e);
        }
    }

    public function rotateKeys(): void
    {
        DB::transaction(function() {
            // Generate new keys
            $this->keys->generateNewKeys();
            
            // Re-encrypt sensitive data
            $this->reEncryptData();
            
            // Update key version
            $this->keys->incrementVersion();
            
            // Backup old keys
            $this->keys->backupOldKeys();
        });
    }

    private function getEncryptionKey(array $options = []): string
    {
        if (isset($options['key'])) {
            return $options['key'];
        }

        return $this->keys->getCurrentKey();
    }

    private function generateTag(string $data, string $iv, string $key): string
    {
        return hash_hmac('sha256', $iv . $data, $key, true);
    }

    private function verifyTag(string $data, string $iv, string $tag, string $key): bool
    {
        $expectedTag = $this->generateTag($data, $iv, $key);
        return hash_equals($expectedTag, $tag);
    }

    private function reEncryptData(): void
    {
        $newKey = $this->keys->getNewKey();
        
        // Re-encrypt stored data
        $this->reEncryptStoredData($newKey);
        
        // Re-encrypt cache
        $this->reEncryptCache($newKey);
        
        // Re-encrypt configs
        $this->reEncryptConfigs($newKey);
    }

    private function reEncryptStoredData(string $newKey): void
    {
        $encryptedData = $this->getEncryptedData();
        
        foreach ($encryptedData as $data) {
            DB::transaction(function() use ($data, $newKey) {
                $decrypted = $this->decrypt($data->value);
                $reEncrypted = $this->encrypt($decrypted, ['key' => $newKey]);
                $data->update(['value' => $reEncrypted]);
            });
        }
    }

    private function reEncryptCache(string $newKey): void
    {
        $encryptedCache = $this->getEncryptedCache();
        
        foreach ($encryptedCache as $key => $value) {
            $decrypted = $this->decrypt($value);
            $reEncrypted = $this->encrypt($decrypted, ['key' => $newKey]);
            cache()->put($key, $reEncrypted);
        }
    }

    private function reEncryptConfigs(string $newKey): void
    {
        $encryptedConfigs = $this->getEncryptedConfigs();
        
        foreach ($encryptedConfigs as $config) {
            DB::transaction(function() use ($config, $newKey) {
                $decrypted = $this->decrypt($config->value);
                $reEncrypted = $this->encrypt($decrypted, ['key' => $newKey]);
                $config->update(['value' => $reEncrypted]);
            });
        }
    }

    private function getEncryptedData(): Collection
    {
        return DB::table('encrypted_data')->get();
    }

    private function getEncryptedCache(): array
    {
        return cache()->tags(['encrypted'])->get();
    }

    private function getEncryptedConfigs(): Collection
    {
        return DB::table('configs')
            ->where('encrypted', true)
            ->get();
    }
}

class KeyManager implements KeyManagerInterface
{
    private KeyStore $store;
    private SecurityManager $security;
    private BackupManager $backup;
    
    public function generateNewKeys(): array
    {
        $keys = [
            'primary' => $this->generateKey(),
            'secondary' => $this->generateKey(),
            'backup' => $this->generateKey()
        ];

        $this->store->storeNewKeys($keys);
        $this->backup->backupKeys($keys);

        return $keys;
    }

    public function getCurrentKey(): string
    {
        return $this->store->getCurrentKey();
    }

    public function getNewKey(): string
    {
        return $this->store->getNewKey();
    }

    public function incrementVersion(): void
    {
        $this->store->incrementVersion();
    }

    public function backupOldKeys(): void
    {
        $this->backup->backupOldKeys(
            $this->store->getOldKeys()
        );
    }

    private function generateKey(): string
    {
        return random_bytes(32);
    }
}
