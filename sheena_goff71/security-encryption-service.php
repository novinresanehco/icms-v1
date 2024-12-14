<?php

namespace App\Core\Security\Services;

use App\Core\Exceptions\EncryptionException;
use App\Core\Security\Models\{EncryptionKey, EncryptedData};
use Illuminate\Support\Facades\Cache;

class EncryptionService
{
    private KeyManager $keyManager;
    private AuditLogger $logger;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        KeyManager $keyManager,
        AuditLogger $logger,
        SecurityConfig $config,
        MetricsCollector $metrics
    ) {
        $this->keyManager = $keyManager;
        $this->logger = $logger;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function encrypt(mixed $data, array $context = []): EncryptedData
    {
        $startTime = microtime(true);

        try {
            $key = $this->keyManager->getCurrentKey();
            
            $serialized = serialize($data);
            $iv = random_bytes(16);
            
            $encrypted = openssl_encrypt(
                $serialized,
                $this->config->getCipher(),
                $key->getValue(),
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption failed');
            }

            $mac = $this->calculateMac($encrypted, $key, $iv);
            
            return new EncryptedData(
                $encrypted,
                $iv,
                $mac,
                $key->getId(),
                $this->config->getCipher()
            );

        } catch (\Exception $e) {
            $this->handleEncryptionFailure('encrypt', $e, $context);
            throw $e;
        } finally {
            $this->recordMetrics('encrypt', $startTime);
        }
    }

    public function decrypt(EncryptedData $encryptedData, array $context = []): mixed
    {
        $startTime = microtime(true);

        try {
            $key = $this->keyManager->getKey($encryptedData->getKeyId());
            
            if (!$this->verifyMac($encryptedData, $key)) {
                throw new EncryptionException('MAC verification failed');
            }

            $decrypted = openssl_decrypt(
                $encryptedData->getData(),
                $encryptedData->getCipher(),
                $key->getValue(),
                OPENSSL_RAW_DATA,
                $encryptedData->getIv()
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            return unserialize($decrypted);

        } catch (\Exception $e) {
            $this->handleEncryptionFailure('decrypt', $e, $context);
            throw $e;
        } finally {
            $this->recordMetrics('decrypt', $startTime);
        }
    }

    public function verifySignature(array $data): bool
    {
        if (empty($data['signature'])) {
            return false;
        }

        $signature = $data['signature'];
        unset($data['signature']);

        $calculated = $this->calculateSignature($data);
        
        return hash_equals($calculated, $signature);
    }

    private function calculateMac(string $encrypted, EncryptionKey $key, string $iv): string
    {
        return hash_hmac(
            'sha256',
            $iv . $encrypted,
            $key->getValue(),
            true
        );
    }

    private function verifyMac(EncryptedData $data, EncryptionKey $key): bool
    {
        $calculated = $this->calculateMac(
            $data->getData(),
            $key,
            $data->getIv()
        );

        return hash_equals($calculated, $data->getMac());
    }

    public function calculateSignature(array $data): string
    {
        ksort($data);
        
        return hash_hmac(
            'sha256',
            serialize($data),
            $this->keyManager->getSigningKey()->getValue(),
            false
        );
    }

    private function handleEncryptionFailure(string $operation, \Exception $e, array $context): void
    {
        $this->logger->logSecurityEvent(
            "encryption_failed_{$operation}",
            [
                'error' => $e->getMessage(),
                'context' => $context,
                'system_info' => [
                    'cipher' => $this->config->getCipher(),
                    'openssl_version' => OPENSSL_VERSION_TEXT
                ]
            ]
        );

        $this->metrics->incrementFailureCount("encryption_{$operation}");
    }

    private function recordMetrics(string $operation, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record("encryption_{$operation}_duration", $duration);
        $this->metrics->increment("encryption_{$operation}_count");
        
        if ($duration > $this->config->getEncryptionThreshold()) {
            $this->logger->logPerformanceIssue(
                "encryption_{$operation}_slow",
                $duration
            );
        }
    }
}
