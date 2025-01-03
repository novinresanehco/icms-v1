<?php

namespace App\Core\Encryption;

use App\Core\Security\SecurityManager;
use App\Core\Metrics\MetricsCollector;
use App\Core\Logging\AuditLogger;

class EncryptionService implements EncryptionInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private AuditLogger $logger;
    private KeyManager $keyManager;
    
    private string $cipher = 'aes-256-gcm';
    private int $keyRotationInterval;
    private array $activeKeys = [];

    public function encrypt(string $data, array $options = []): string
    {
        $encryptionId = $this->generateEncryptionId();
        
        try {
            $this->validateEncryptionRequest($data, $options);
            $this->security->validateAccess('encryption.encrypt');

            $key = $this->getEncryptionKey($options);
            $iv = $this->generateIV();
            $tag = '';

            $encrypted = openssl_encrypt(
                $data,
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption failed');
            }

            $result = $this->packEncryptedData($encrypted, $iv, $tag, $options);
            $this->logEncryption($encryptionId, $options);

            return $result;

        } catch (\Exception $e) {
            $this->handleEncryptionFailure($e, $options);
            throw $e;
        }
    }

    public function decrypt(string $encrypted, array $options = []): string
    {
        try {
            $this->validateDecryptionRequest($encrypted, $options);
            $this->security->validateAccess('encryption.decrypt');

            [$data, $iv, $tag] = $this->unpackEncryptedData($encrypted);
            $key = $this->getDecryptionKey($options);

            $decrypted = openssl_decrypt(
                $data,
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            $this->logDecryption($options);
            return $decrypted;

        } catch (\Exception $e) {
            $this->handleDecryptionFailure($e, $options);
            throw $e;
        }
    }

    public function rotateKeys(): void
    {
        try {
            $this->security->validateAccess('encryption.rotate_keys');
            
            $newKey = $this->keyManager->generateKey();
            $keyId = $this->keyManager->storeKey($newKey);
            
            $this->activeKeys = array_merge(
                [$keyId => $newKey],
                array_slice($this->activeKeys, 0, 2, true)
            );

            $this->logKeyRotation($keyId);

        } catch (\Exception $e) {
            $this->handleKeyRotationFailure($e);
            throw $e;
        }
    }

    public function sign(string $data): string
    {
        try {
            $this->security->validateAccess('encryption.sign');
            
            $key = $this->getSigningKey();
            $signature = hash_hmac('sha256', $data, $key, true);
            
            return base64_encode($signature);

        } catch (\Exception $e) {
            $this->handleSigningFailure($e);
            throw $e;
        }
    }

    public function verify(string $data, string $signature): bool
    {
        try {
            $this->security->validateAccess('encryption.verify');
            
            $key = $this->getSigningKey();
            $expected = hash_hmac('sha256', $data, $key, true);
            
            return hash_equals($expected, base64_decode($signature));

        } catch (\Exception $e) {
            $this->handleVerificationFailure($e);
            throw $e;
        }
    }

    protected function validateEncryptionRequest(string $data, array $options): void
    {
        if (empty($data)) {
            throw new EncryptionException('Empty data for encryption');
        }

        if (isset($options['key_id']) && !$this->keyManager->keyExists($options['key_id'])) {
            throw new EncryptionException('Invalid encryption key ID');
        }
    }

    protected function validateDecryptionRequest(string $encrypted, array $options): void
    {
        if (empty($encrypted)) {
            throw new EncryptionException('Empty data for decryption');
        }

        if (!$this->isValidEncryptedFormat($encrypted)) {
            throw new EncryptionException('Invalid encrypted data format');
        }
    }

    protected function getEncryptionKey(array $options): string
    {
        if (isset($options['key_id'])) {
            return $this->keyManager->getKey($options['key_id']);
        }

        return reset($this->activeKeys);
    }

    protected function getDecryptionKey(array $options): string
    {
        if (isset($options['key_id'])) {
            return $this->keyManager->getKey($options['key_id']);
        }

        $keyId = $this->extractKeyId($options);
        return $this->keyManager->getKey($keyId);
    }

    protected function packEncryptedData(string $encrypted, string $iv, string $tag, array $options): string
    {
        $keyId = key($this->activeKeys);
        
        $packed = pack('Na*a*a*', 
            strlen($iv),
            $iv,
            $tag,
            $encrypted
        );

        return base64_encode($keyId . $packed);
    }

    protected function unpackEncryptedData(string $encrypted): array
    {
        $binary = base64_decode($encrypted);
        
        $keyId = substr($binary, 0, 32);
        $data = substr($binary, 32);
        
        $ivLength = unpack('N', substr($data, 0, 4))[1];
        $iv = substr($data, 4, $ivLength);
        $tag = substr($data, 4 + $ivLength, 16);
        $encrypted = substr($data, 4 + $ivLength + 16);

        return [$encrypted, $iv, $tag];
    }

    protected function generateIV(): string
    {
        return random_bytes(openssl_cipher_iv_length($this->cipher));
    }

    protected function isValidEncryptedFormat(string $encrypted): bool
    {
        try {
            $decoded = base64_decode($encrypted, true);
            return $decoded !== false && strlen($decoded) > 52;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function logEncryption(string $encryptionId, array $options): void
    {
        $this->logger->info('Data encrypted', [
            'encryption_id' => $encryptionId,
            'key_id' => $options['key_id'] ?? key($this->activeKeys)
        ]);

        $this->metrics->increment('encryption.operations');
    }

    protected function logDecryption(array $options): void
    {
        $this->metrics->increment('decryption.operations');
    }

    protected function logKeyRotation(string $keyId): void
    {
        $this->logger->info('Encryption key rotated', [
            'key_id' => $keyId
        ]);

        $this->metrics->increment('encryption.key_rotations');
    }

    private function generateEncryptionId(): string
    {
        return 'enc_' . md5(uniqid(mt_rand(), true));
    }

    private function getSigningKey(): string
    {
        return $this->keyManager->getSigningKey();
    }
}
