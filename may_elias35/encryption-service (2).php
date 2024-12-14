<?php

namespace App\Core\Security;

use App\Core\Monitoring\SystemMonitor;
use App\Core\Exceptions\EncryptionException;

class EncryptionService implements EncryptionInterface
{
    private SystemMonitor $monitor;
    private array $config;
    private array $activeKeys = [];

    public function __construct(
        SystemMonitor $monitor,
        array $config
    ) {
        $this->monitor = $monitor;
        $this->config = $config;
        $this->initializeKeys();
    }

    public function encrypt(string $data, array $options = []): string 
    {
        $monitoringId = $this->monitor->startOperation('encryption');
        
        try {
            $this->validateEncryptionState();
            
            $key = $this->getActiveKey($options['key_type'] ?? 'default');
            $iv = $this->generateIV();
            
            $encrypted = openssl_encrypt(
                $data,
                $this->config['cipher'],
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption failed');
            }

            $mac = $this->calculateMAC($encrypted, $key);
            
            $package = $this->packageEncryptedData($encrypted, $iv, $mac);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $package;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new EncryptionException('Encryption failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function decrypt(string $package, array $options = []): string
    {
        $monitoringId = $this->monitor->startOperation('decryption');
        
        try {
            $this->validateEncryptionState();
            
            $data = $this->unpackageEncryptedData($package);
            
            $key = $this->getActiveKey($options['key_type'] ?? 'default');
            
            $this->verifyMAC($data['encrypted'], $data['mac'], $key);
            
            $decrypted = openssl_decrypt(
                $data['encrypted'],
                $this->config['cipher'],
                $key,
                OPENSSL_RAW_DATA,
                $data['iv']
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $decrypted;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new EncryptionException('Decryption failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function rotateKeys(): void
    {
        $monitoringId = $this->monitor->startOperation('key_rotation');
        
        try {
            foreach ($this->config['key_types'] as $type) {
                $this->rotateKeyType($type);
            }
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new EncryptionException('Key rotation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateEncryptionState(): void
    {
        if (empty($this->activeKeys)) {
            throw new EncryptionException('No active encryption keys');
        }

        if (!$this->validateCipherAvailability()) {
            throw new EncryptionException('Required cipher not available');
        }
    }

    private function initializeKeys(): void
    {
        foreach ($this->config['key_types'] as $type) {
            $this->activeKeys[$type] = $this->generateKey();
        }
    }

    private function rotateKeyType(string $type): void
    {
        $newKey = $this->generateKey();
        $oldKey = $this->activeKeys[$type];
        
        $this->activeKeys[$type] = $newKey;
        
        $this->storeRotatedKey($type, $oldKey);
    }

    private function generateKey(): string
    {
        $key = random_bytes($this->config['key_length']);
        
        if (strlen($key) !== $this->config['key_length']) {
            throw new EncryptionException('Key generation failed');
        }
        
        return $key;
    }

    private function generateIV(): string
    {
        $ivLength = openssl_cipher_iv_length($this->config['cipher']);
        
        if ($ivLength === false) {
            throw new EncryptionException('Failed to determine IV length');
        }
        
        $iv = random_bytes($ivLength);
        
        if (strlen($iv) !== $ivLength) {
            throw new EncryptionException('IV generation failed');
        }
        
        return $iv;
    }

    private function calculateMAC(string $data, string $key): string
    {
        return hash_hmac(
            'sha256',
            $data,
            $key,
            true
        );
    }

    private function verifyMAC(string $data, string $mac, string $key): void
    {
        $calculated = $this->calculateMAC($data, $key);
        
        if (!hash_equals($calculated, $mac)) {
            throw new EncryptionException('MAC verification failed');
        }
    }

    private function packageEncryptedData(string $encrypted, string $iv, string $mac): string
    {
        $package = [
            'encrypted' => base64_encode($encrypted),
            'iv' => base64_encode($iv),
            'mac' => base64_encode($mac)
        ];
        
        return json_encode($package);
    }

    private function unpackageEncryptedData(string $package): array
    {
        $data = json_decode($package, true);
        
        if (!$this->validatePackageStructure($data)) {
            throw new EncryptionException('Invalid encryption package');
        }
        
        return [
            'encrypted' => base64_decode($data['encrypted']),
            'iv' => base64_decode($data['iv']),
            'mac' => base64_decode($data['mac'])
        ];
    }

    private function validatePackageStructure(array $data): bool
    {
        return isset($data['encrypted']) &&
               isset($data['iv']) &&
               isset($data['mac']);
    }

    private function validateCipherAvailability(): bool
    {
        return in_array(
            $this->config['cipher'],
            openssl_get_cipher_methods()
        );
    }

    private function getActiveKey(string $type): string
    {
        if (!isset($this->activeKeys[$type])) {
            throw new EncryptionException("No active key for type: {$type}");
        }
        
        return $this->activeKeys[$type];
    }

    private function storeRotatedKey(string $type, string $key): void
    {
        // Implementation depends on key storage mechanism
        // Must be secure and persist old keys for potential decryption needs
    }
}
