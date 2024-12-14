<?php

namespace App\Core\Security;

use App\Core\Contracts\HashInterface;
use Illuminate\Support\Facades\{Cache, Log};
use App\Exceptions\HashingException;

class HashService implements HashInterface
{
    private string $primaryKey;
    private string $backupKey;
    private array $algorithms;
    private int $keyRotationInterval;

    public function __construct(
        SecurityConfig $config
    ) {
        $this->primaryKey = $config->getHashingKey();
        $this->backupKey = $config->getBackupHashingKey();
        $this->algorithms = $config->getHashingAlgorithms();
        $this->keyRotationInterval = $config->getKeyRotationInterval();
    }

    public function generateHash(mixed $data): string
    {
        try {
            $serialized = $this->serializeData($data);
            $salt = $this->generateSalt();
            $key = $this->getCurrentKey();

            $hash = $this->computeHash($serialized, $salt, $key);
            $this->validateHash($hash);
            $this->cacheHash($hash, $salt);

            return $this->formatHash($hash, $salt);

        } catch (\Exception $e) {
            $this->handleHashingError($e, $data);
            throw new HashingException('Hash generation failed', 0, $e);
        }
    }

    public function verifyHash(mixed $data, string $hash): bool
    {
        try {
            [$hashValue, $salt] = $this->parseHash($hash);
            $serialized = $this->serializeData($data);

            // Try with primary key
            if ($this->verifyWithKey($serialized, $salt, $hashValue, $this->primaryKey)) {
                return true;
            }

            // Fallback to backup key
            return $this->verifyWithKey($serialized, $salt, $hashValue, $this->backupKey);

        } catch (\Exception $e) {
            $this->handleVerificationError($e, $data, $hash);
            return false;
        }
    }

    public function verifyToken(string $token): bool
    {
        try {
            if (!$this->isValidTokenFormat($token)) {
                return false;
            }

            $tokenData = $this->parseToken($token);
            if ($this->isTokenExpired($tokenData)) {
                return false;
            }

            return $this->verifyTokenSignature($tokenData);

        } catch (\Exception $e) {
            $this->handleTokenError($e, $token);
            return false;
        }
    }

    protected function generateSalt(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function getCurrentKey(): string
    {
        $this->rotateKeyIfNeeded();
        return $this->primaryKey;
    }

    protected function rotateKeyIfNeeded(): void
    {
        $lastRotation = Cache::get('last_key_rotation');
        if (!$lastRotation || time() - $lastRotation > $this->keyRotationInterval) {
            $this->rotateKeys();
        }
    }

    protected function rotateKeys(): void
    {
        try {
            $newKey = $this->generateNewKey();
            $this->backupKey = $this->primaryKey;
            $this->primaryKey = $newKey;
            Cache::put('last_key_rotation', time(), $this->keyRotationInterval);
            
            $this->logKeyRotation();
            
        } catch (\Exception $e) {
            Log::error('Key rotation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new HashingException('Key rotation failed', 0, $e);
        }
    }

    protected function generateNewKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    protected function computeHash(string $data, string $salt, string $key): string
    {
        $hash = '';
        foreach ($this->algorithms as $algorithm) {
            $hash = hash_hmac($algorithm, $data . $salt, $key);
        }
        return $hash;
    }

    protected function validateHash(string $hash): void
    {
        if (strlen($hash) !== 64) { // SHA-256 length
            throw new HashingException('Invalid hash length');
        }

        if (!ctype_xdigit($hash)) {
            throw new HashingException('Invalid hash format');
        }
    }

    protected function cacheHash(string $hash, string $salt): void
    {
        Cache::put("hash:$hash", [
            'salt' => $salt,
            'created' => time()
        ], 3600);
    }

    protected function formatHash(string $hash, string $salt): string
    {
        return "$hash:$salt";
    }

    protected function parseHash(string $hash): array
    {
        $parts = explode(':', $hash);
        if (count($parts) !== 2) {
            throw new HashingException('Invalid hash format');
        }
        return $parts;
    }

    protected function verifyWithKey(
        string $data,
        string $salt,
        string $hash,
        string $key
    ): bool {
        $computed = $this->computeHash($data, $salt, $key);
        return hash_equals($computed, $hash);
    }

    protected function serializeData(mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }
        return json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    protected function isValidTokenFormat(string $token): bool
    {
        return preg_match('/^[A-Fa-f0-9]{64}\.[A-Fa-f0-9]{32}\.\d+$/', $token);
    }

    protected function parseToken(string $token): array
    {
        [$signature, $payload, $timestamp] = explode('.', $token);
        return [
            'signature' => $signature,
            'payload' => $payload,
            'timestamp' => (int)$timestamp
        ];
    }

    protected function isTokenExpired(array $tokenData): bool
    {
        $maxAge = $this->config->getTokenMaxAge();
        return (time() - $tokenData['timestamp']) > $maxAge;
    }

    protected function verifyTokenSignature(array $tokenData): bool
    {
        $expected = $this->computeHash(
            $tokenData['payload'] . $tokenData['timestamp'],
            '',
            $this->primaryKey
        );
        return hash_equals($expected, $tokenData['signature']);
    }

    protected function handleHashingError(\Exception $e, mixed $data): void
    {
        Log::error('Hashing failed', [
            'error' => $e->getMessage(),
            'data_type' => gettype($data),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleVerificationError(\Exception $e, mixed $data, string $hash): void
    {
        Log::error('Hash verification failed', [
            'error' => $e->getMessage(),
            'data_type' => gettype($data),
            'hash' => $hash,
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleTokenError(\Exception $e, string $token): void
    {
        Log::error('Token verification failed', [
            'error' => $e->getMessage(),
            'token_length' => strlen($token),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function logKeyRotation(): void
    {
        Log::info('Cryptographic keys rotated', [
            'timestamp' => time(),
            'next_rotation' => time() + $this->keyRotationInterval
        ]);
    }
}
