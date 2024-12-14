<?php

namespace App\Core\Security\Keys;

class CriticalKeyManager
{
    private $storage;
    private $monitor;

    public function getActiveKey(string $context): string
    {
        try {
            // Get current key version
            $version = $this->storage->getCurrentVersion($context);
            
            // Load and verify key
            $key = $this->loadKey($context, $version);
            if (!$this->verifyKey($key)) {
                throw new KeyException('Key verification failed');
            }

            return $key;

        } catch (\Exception $e) {
            $this->monitor->keyFailure($context, $e);
            throw new KeyException('Failed to get active key', 0, $e);
        }
    }

    public function rotateKeys(): void
    {
        DB::transaction(function() {
            try {
                // Generate new keys
                $this->generateNewKeys();
                
                // Update active versions
                $this->updateKeyVersions();
                
                // Verify rotation
                $this->verifyKeyRotation();
                
                // Clean old keys
                $this->cleanupOldKeys();

            } catch (\Exception $e) {
                $this->monitor->rotationFailure($e);
                throw $e;
            }
        });
    }

    private function verifyKey(string $key): bool
    {
        return strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES;
    }

    private function generateNewKeys(): array
    {
        return [
            'master' => sodium_crypto_secretbox_keygen(),
            'data' => sodium_crypto_secretbox_keygen(),
            'auth' => sodium_crypto_secretbox_keygen()
        ];
    }
}
