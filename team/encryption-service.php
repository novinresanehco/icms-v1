namespace App\Core\Security;

class EncryptionService implements EncryptionInterface
{
    private KeyManager $keyManager;
    private CacheManager $cache;
    private LogManager $logger;
    private MetricsCollector $metrics;
    private EncryptionConfig $config;

    public function __construct(
        KeyManager $keyManager,
        CacheManager $cache,
        LogManager $logger,
        MetricsCollector $metrics,
        EncryptionConfig $config
    ) {
        $this->keyManager = $keyManager;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function encrypt(string $data, ?string $context = null): EncryptedData
    {
        $startTime = microtime(true);

        try {
            $key = $this->keyManager->getActiveKey($context);
            $iv = random_bytes(16);
            $tag = '';

            $encrypted = openssl_encrypt(
                $data,
                $this->config->getCipher(),
                $key->getSecret(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $this->getAad($context),
                $this->config->getTagLength()
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption failed');
            }

            $result = new EncryptedData(
                $encrypted,
                $iv,
                $tag,
                $key->getId(),
                $this->config->getCipher()
            );

            $this->logEncryption($context, $startTime);
            return $result;

        } catch (\Exception $e) {
            $this->handleEncryptionFailure($e, $context);
            throw new EncryptionException(
                'Encryption failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function decrypt(EncryptedData $data, ?string $context = null): string
    {
        $startTime = microtime(true);

        try {
            $key = $this->keyManager->getKeyById($data->keyId);

            $decrypted = openssl_decrypt(
                $data->ciphertext,
                $data->cipher,
                $key->getSecret(),
                OPENSSL_RAW_DATA,
                $data->iv,
                $data->tag,
                $this->getAad($context)
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            $this->logDecryption($context, $startTime);
            return $decrypted;

        } catch (\Exception $e) {
            $this->handleDecryptionFailure($e, $context);
            throw new EncryptionException(
                'Decryption failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function hash(string $data): string
    {
        $startTime = microtime(true);

        try {
            $hash = hash_hmac(
                $this->config->getHashAlgorithm(),
                $data,
                $this->keyManager->getHashKey()->getSecret(),
                true
            );

            $this->logHash($startTime);
            return base64_encode($hash);

        } catch (\Exception $e) {
            $this->handleHashFailure($e);
            throw new EncryptionException(
                'Hash generation failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function verifyHash(string $data, string $hash): bool
    {
        $startTime = microtime(true);

        try {
            $calculated = $this->hash($data);
            $result = hash_equals($hash, $calculated);

            $this->logHashVerification($result, $startTime);
            return $result;

        } catch (\Exception $e) {
            $this->handleHashVerificationFailure($e);
            throw new EncryptionException(
                'Hash verification failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function getAad(?string $context): string
    {
        if ($context === null) {
            return '';
        }

        return hash_hmac(
            $this->config->getHashAlgorithm(),
            $context,
            $this->keyManager->getAadKey()->getSecret(),
            true
        );
    }

    private function logEncryption(?string $context, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->logger->debug('Data encrypted', [
            'duration' => $duration,
            'context' => $context,
            'cipher' => $this->config->getCipher()
        ]);

        $this->metrics->recordOperation(
            'encryption',
            $duration,
            ['context' => $context]
        );
    }

    private function logDecryption(?string $context, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->logger->debug('Data decrypted', [
            'duration' => $duration,
            'context' => $context
        ]);

        $this->metrics->recordOperation(
            'decryption',
            $duration,
            ['context' => $context]
        );
    }

    private function logHash(float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->logger->debug('Hash generated', [
            'duration' => $duration,
            'algorithm' => $this->config->getHashAlgorithm()
        ]);

        $this->metrics->recordOperation('hash', $duration);
    }

    private function logHashVerification(bool $result, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->logger->debug('Hash verified', [
            'duration' => $duration,
            'result' => $result
        ]);

        $this->metrics->recordOperation(
            'hash_verification',
            $duration,
            ['result' => $result]
        );
    }

    private function handleEncryptionFailure(\Exception $e, ?string $context): void
    {
        $this->logger->error('Encryption failed', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementFailureCount('encryption');
    }

    private function handleDecryptionFailure(\Exception $e, ?string $context): void
    {
        $this->logger->error('Decryption failed', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementFailureCount('decryption');
    }

    private function handleHashFailure(\Exception $e): void
    {
        $this->logger->error('Hash generation failed', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementFailureCount('hash');
    }

    private function handleHashVerificationFailure(\Exception $e): void
    {
        $this->logger->error('Hash verification failed', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementFailureCount('hash_verification');
    }
}
