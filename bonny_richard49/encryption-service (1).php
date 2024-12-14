<?php

namespace App\Core\Security\Services;

use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\EncryptionInterface;
use App\Core\Security\Exceptions\EncryptionException;

class EncryptionService implements EncryptionInterface
{
    private string $masterKey;
    private array $config;
    private KeyManager $keyManager;

    private const CIPHER = 'aes-256-gcm';
    private const KEY_SIZE = 32;
    private const TAG_LENGTH = 16;

    public function __construct(
        string $masterKey,
        array $config,
        KeyManager $keyManager
    ) {
        $this->masterKey = $masterKey;
        $this->config = $config;
        $this->keyManager = $keyManager;
    }

    public function encrypt(string $data, array $context = []): EncryptedData
    {
        try {
            $key = $this->keyManager->getDerivedKey($context);
            $iv = random_bytes(12);
            $tag = '';

            $ciphertext = openssl_encrypt(
                $data,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                self::TAG_LENGTH
            );

            if ($ciphertext === false) {
                throw new EncryptionException('Encryption failed');
            }

            return new EncryptedData(
                $ciphertext,
                $iv,
                $tag,
                $this->generateMetadata($context)
            );
        } catch (\Exception $e) {
            throw new EncryptionException(
                'Encryption error: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function decrypt(EncryptedData $encryptedData, array $context = []): string
    {
        try {
            $this->validateEncryptedData($encryptedData);
            
            $key = $this->keyManager->getDerivedKey($context);
            
            $decrypted = openssl_decrypt(
                $encryptedData->ciphertext,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $encryptedData->iv,
                $encryptedData->tag
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            $this->verifyDecryptedData($decrypted, $encryptedData->metadata);
            
            return $decrypted;
        } catch (\Exception $e) {
            throw new EncryptionException(
                'Decryption error: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function protectData(array $data, array $context = []): array
    {
        $protected = [];
        
        foreach ($data as $key => $value) {
            $protected[$key] = $this->protectValue($value, $context);
        }

        $protected['_metadata'] = $this->generateMetadata($context);
        $protected['_hash'] = $this->calculateHash($protected);

        return $protected;
    }

    public function verifyIntegrity(array $data): bool
    {
        if (!isset($data['_hash']) || !isset($data['_metadata'])) {
            return false;
        }

        $providedHash = $data['_hash'];
        unset($data['_hash']);

        $calculatedHash = $this->calculateHash($data);
        return hash_equals($providedHash, $calculatedHash);
    }

    private function protectValue($value, array $context): mixed
    {
        if (is_string($value) && $this->shouldEncrypt($value)) {
            return $this->encrypt($value, $context);
        } elseif (is_array($value)) {
            return $this->protectData($value, $context);
        }
        return $value;
    }

    private function shouldEncrypt(string $value): bool
    {
        return $this->containsSensitiveData($value) 
            || strlen($value) > $this->config['min_encryption_length'];
    }

    private function containsSensitiveData(string $value): bool
    {
        foreach ($this->config['sensitive_patterns'] as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        return false;
    }

    private function validateEncryptedData(EncryptedData $data): void
    {
        if (empty($data->ciphertext) || empty($data->iv) || empty($data->tag)) {
            throw new EncryptionException('Invalid encrypted data structure');
        }

        if (!$this->verifyMetadata($data->metadata)) {
            throw new EncryptionException('Invalid encryption metadata');
        }
    }

    private function verifyMetadata(array $metadata): bool
    {
        return isset($metadata['timestamp'])
            && isset($metadata['version'])
            && isset($metadata['algorithm'])
            && $metadata['algorithm'] === self::CIPHER;
    }

    private function verifyDecryptedData(string $data, array $metadata): void
    {
        if (isset($metadata['hash'])) {
            $hash = hash('sha256', $data, true);
            if (!hash_equals($metadata['hash'], $hash)) {
                throw new EncryptionException('Decrypted data integrity check failed');
            }
        }
    }

    private function generateMetadata(array $context): array
    {
        return [
            'timestamp' => time(),
            'version' => $this->config['version'],
            'algorithm' => self::CIPHER,
            'context' => $this->sanitizeContext($context)
        ];
    }

    private function sanitizeContext(array $context): array
    {
        return array_intersect_key(
            $context,
            array_flip($this->config['allowed_context_keys'])
        );
    }

    private function calculateHash(array $data): string
    {
        ksort($data);
        $serialized = serialize($data);
        return hash_hmac('sha256', $serialized, $this->masterKey);
    }
}

class EncryptedData
{
    public function __construct(
        public readonly string $ciphertext,
        public readonly string $iv,
        public readonly string $tag,
        public readonly array $metadata
    ) {}
}
