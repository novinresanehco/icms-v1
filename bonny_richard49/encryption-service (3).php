<?php

namespace App\Core\Services;

use App\Core\Interfaces\EncryptionServiceInterface;
use App\Core\Exceptions\EncryptionException;
use Illuminate\Support\Facades\Config;
use Psr\Log\LoggerInterface;

class EncryptionService implements EncryptionServiceInterface
{
    private LoggerInterface $logger;
    private string $key;
    private string $cipher;
    private array $config;

    private const CIPHER_METHOD = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const KEY_ITERATIONS = 100000;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->config = Config::get('encryption');
        $this->key = base64_decode($this->config['key']);
        $this->cipher = self::CIPHER_METHOD;
    }

    public function encrypt(string $data, ?string $key = null): string
    {
        try {
            $iv = random_bytes(16);
            $key = $key ? $this->deriveKey($key) : $this->key;
            $tag = '';

            $encrypted = openssl_encrypt(
                $data,
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                self::TAG_LENGTH
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption failed');
            }

            $combined = $iv . $tag . $encrypted;
            return base64_encode($combined);

        } catch (\Exception $e) {
            $this->handleError('Encryption failed', $e);
        }
    }

    public function decrypt(string $data, ?string $key = null): string
    {
        try {
            $decoded = base64_decode($data);
            $key = $key ? $this->deriveKey($key) : $this->key;

            $iv = substr($decoded, 0, 16);
            $tag = substr($decoded, 16, self::TAG_LENGTH);
            $encrypted = substr($decoded, 16 + self::TAG_LENGTH);

            $decrypted = openssl_decrypt(
                $encrypted,
                $this->cipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            return $decrypted;

        } catch (\Exception $e) {
            $this->handleError('Decryption failed', $e);
        }
    }

    public function hash(string $data, string $salt = ''): string
    {
        try {
            return hash_hmac('sha256', $data . $salt, $this->key);
        } catch (\Exception $e) {
            $this->handleError('Hashing failed', $e);
        }
    }

    public function verify(string $data, string $hash, string $salt = ''): bool
    {
        try {
            return hash_equals($this->hash($data, $salt), $hash);
        } catch (\Exception $e) {
            $this->handleError('Hash verification failed', $e);
        }
    }

    public function generateKey(): string
    {
        try {
            return base64_encode(random_bytes(32));
        } catch (\Exception $e) {
            $this->handleError('Key generation failed', $e);
        }
    }

    private function deriveKey(string $password): string
    {
        $salt = random_bytes(16);
        
        $key = openssl_pbkdf2(
            $password,
            $salt,
            32,
            self::KEY_ITERATIONS,
            'sha256'
        );

        if ($key === false) {
            throw new EncryptionException('Key derivation failed');
        }

        return $key;
    }

    private function handleError(string $message, \Exception $e): void
    {
        $this->logger->error($message, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        throw new EncryptionException($message, 0, $e);
    }
}
