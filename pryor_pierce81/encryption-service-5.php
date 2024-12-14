<?php

namespace App\Core\Security\Encryption;

use App\Core\Exception\EncryptionException;
use App\Core\Security\SecurityManagerInterface;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\DB;

class EncryptionService implements EncryptionInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $keyCache = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initializeKeys();
    }

    public function encrypt(string $data): string
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('encryption:encrypt', [
                'operation_id' => $operationId
            ]);

            $iv = $this->generateIV();
            $key = $this->getCurrentKey();

            $encrypted = openssl_encrypt(
                $data,
                $this->config['cipher'],
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption operation failed');
            }

            $this->logEncryption($operationId, 'encrypt');

            return $this->formatEncryptedData($encrypted, $iv);

        } catch (\Exception $e) {
            $this->handleEncryptionFailure($operationId, 'encrypt', $e);
            throw new EncryptionException('Data encryption failed', 0, $e);
        }
    }

    public function decrypt(string $encryptedData): string
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('encryption:decrypt', [
                'operation_id' => $operationId
            ]);

            list($encrypted, $iv) = $this->parseEncryptedData($encryptedData);
            $key = $this->getCurrentKey();

            $decrypted = openssl_decrypt(
                $encrypted,
                $this->config['cipher'],
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption operation failed');
            }

            $this->logEncryption($operationId, 'decrypt');

            return $decrypted;

        } catch (\Exception $e) {
            $this->handleEncryptionFailure($operationId, 'decrypt', $e);
            throw new EncryptionException('Data decryption failed', 0, $e);
        }
    }

    public function rotateKeys(): void
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('encryption:rotate', [
                'operation_id' => $operationId
            ]);

            $newKey = $this->generateKey();
            $this->storeKey($newKey);
            $this->updateCurrentKey($newKey);

            $this->logEncryption($operationId, 'rotate');

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleEncryptionFailure($operationId, 'rotate', $e);
            throw new EncryptionException('Key rotation failed', 0, $e);
        }
    }

    public function validateKey(string $key): bool
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('encryption:validate', [
                'operation_id' => $operationId
            ]);

            return $this->verifyKeyFormat($key) && $this->verifyKeyStrength($key);

        } catch (\Exception $e) {
            $this->handleEncryptionFailure($operationId, 'validate', $e);
            throw new EncryptionException('Key validation failed', 0, $e);
        }
    }

    private function initializeKeys(): void
    {
        if (!$this->hasValidKey()) {
            $this->rotateKeys();
        }
    }

    private function generateKey(): string
    {
        $key = random_bytes($this->config['key_length']);
        
        if (!$this->validateKey($key)) {
            throw new EncryptionException('Generated key validation failed');
        }

        return $key;
    }

    private function generateIV(): string
    {
        return random_bytes(openssl_cipher_iv_length($this->config['cipher']));
    }

    private function getCurrentKey(): string
    {
        $keyId = $this->getCurrentKeyId();
        
        if (isset($this->keyCache[$keyId])) {
            return $this->keyCache[$keyId];
        }

        $key = $this->loadKey($keyId);
        $this->keyCache[$keyId] = $key;

        return $key;
    }

    private function getCurrentKeyId(): string
    {
        return Cache::remember('current_encryption_key_id', 300, function() {
            return DB::table('encryption_keys')
                ->where('status', 'active')
                ->value('id');
        });
    }

    private function loadKey(string $keyId): string
    {
        $encryptedKey = DB::table('encryption_keys')
            ->where('id', $keyId)
            ->value('key_data');

        if (!$encryptedKey) {
            throw new EncryptionException('Encryption key not found');
        }

        return $this->decryptKey($encryptedKey);
    }

    private function storeKey(string $key): string
    {
        $keyId = uniqid('key_', true);
        $encryptedKey = $this->encryptKey($key);

        DB::table('encryption_keys')->insert([
            'id' => $keyId,
            'key_data' => $encryptedKey,
            'created_at' => now(),
            'status' => 'inactive'
        ]);

        return $keyId;
    }

    private function updateCurrentKey(string $newKey): void
    {
        $currentKeyId = $this->getCurrentKeyId();
        
        if ($currentKeyId) {
            DB::table('encryption_keys')
                ->where('id', $currentKeyId)
                ->update(['status' => 'archived']);
        }

        $newKeyId = $this->storeKey($newKey);
        
        DB::table('encryption_keys')
            ->where('id', $newKeyId)
            ->update(['status' => 'active']);

        Cache::forget('current_encryption_key_id');
    }

    private function formatEncryptedData(string $encrypted, string $iv): string
    {
        $data = base64_encode($iv . $encrypted);
        $hmac = hash_hmac('sha256', $data, $this->getCurrentKey());
        
        return $hmac . ':' . $data;
    }

    private function parseEncryptedData(string $encryptedData): array
    {
        $parts = explode(':', $encryptedData);
        
        if (count($parts) !== 2) {
            throw new EncryptionException('Invalid encrypted data format');
        }

        $hmac = $parts[0];
        $data = $parts[1];

        if (!hash_equals($hmac, hash_hmac('sha256', $data, $this->getCurrentKey()))) {
            throw new EncryptionException('Data integrity check failed');
        }

        $decoded = base64_decode($data);
        $ivLength = openssl_cipher_iv_length($this->config['cipher']);
        
        return [
            substr($decoded, $ivLength),
            substr($decoded, 0, $ivLength)
        ];
    }

    private function verifyKeyFormat(string $key): bool
    {
        return strlen($key) === $this->config['key_length'];
    }

    private function verifyKeyStrength(string $key): bool
    {
        $entropy = 0;
        $size = strlen($key);
        $counts = array_count_values(str_split($key));
        
        foreach ($counts as $count) {
            $probability = $count / $size;
            $entropy -= $probability * log($probability, 2);
        }
        
        return $entropy >= $this->config['min_key_entropy'];
    }

    private function getDefaultConfig(): array
    {
        return [
            'cipher' => 'AES-256-GCM',
            'key_length' => 32,
            'min_key_entropy' => 7.5,
            'rotation_interval' => 86400,
            'key_cache_ttl' => 3600
        ];
    }

    private function generateOperationId(): string
    {
        return uniqid('enc_', true);
    }

    private function handleEncryptionFailure(string $operationId, string $operation, \Exception $e): void
    {
        $this->logger->error('Encryption operation failed', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
