<?php

namespace App\Core\Security\Storage;

class SecureKeyStorage 
{
    private $config;
    private $monitor;

    public function storeKey(string $key, string $version, string $context): void
    {
        try {
            // Encrypt key for storage
            $encrypted = $this->encryptForStorage($key);
            
            // Store with metadata
            $this->storeEncryptedKey($encrypted, $version, $context);
            
            // Verify storage
            $this->verifyKeyStorage($version, $context);

        } catch (\Exception $e) {
            $this->monitor->storageFailure($e);
            throw new StorageException('Key storage failed', 0, $e);
        }
    }

    private function encryptForStorage(string $key): string 
    {
        $masterKey = $this->config->getMasterKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        
        return base64_encode($nonce . sodium_crypto_secretbox($key, $nonce, $masterKey));
    }

    private function storeEncryptedKey(string $encrypted, string $version, string $context): void 
    {
        $this->storage->put(
            $this->getKeyPath($version, $context),
            $encrypted
        );
    }

    private function verifyKeyStorage(string $version, string $context): void
    {
        if (!$this->storage->exists($this->getKeyPath($version, $context))) {
            throw new StorageException('Key storage verification failed');
        }
    }
}
