namespace App\Core\Security;

class EncryptionService implements EncryptionInterface
{
    private KeyManager $keyManager;
    private MetricsCollector $metrics;
    private AuditLogger $audit;
    private string $cipher;
    private array $options;

    public function __construct(
        KeyManager $keyManager,
        MetricsCollector $metrics,
        AuditLogger $audit
    ) {
        $this->keyManager = $keyManager;
        $this->metrics = $metrics;
        $this->audit = $audit;
        $this->cipher = 'aes-256-gcm';
        $this->options = [
            'tag_length' => 16,
            'key_iterations' => 10000,
            'memory_cost' => 2048,
            'time_cost' => 4
        ];
    }

    public function encrypt(string $data, array $context = []): EncryptedData
    {
        $startTime = microtime(true);

        try {
            $key = $this->keyManager->getActiveKey();
            $iv = random_bytes(16);
            $tag = '';

            $encrypted = openssl_encrypt(
                $data,
                $this->cipher,
                $key->getSecret(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                $this->options['tag_length']
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption failed');
            }

            $this->metrics->timing(
                'encryption.duration',
                microtime(true) - $startTime
            );

            return new EncryptedData(
                $encrypted,
                $iv,
                $tag,
                $key->getId(),
                $this->cipher
            );

        } catch (\Exception $e) {
            $this->handleEncryptionFailure($e, $context);
            throw $e;
        }
    }

    public function decrypt(EncryptedData $encryptedData, array $context = []): string
    {
        $startTime = microtime(true);

        try {
            $key = $this->keyManager->getKey($encryptedData->getKeyId());

            $decrypted = openssl_decrypt(
                $encryptedData->getData(),
                $encryptedData->getCipher(),
                $key->getSecret(),
                OPENSSL_RAW_DATA,
                $encryptedData->getIv(),
                $encryptedData->getTag()
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            $this->metrics->timing(
                'decryption.duration',
                microtime(true) - $startTime
            );

            return $decrypted;

        } catch (\Exception $e) {
            $this->handleDecryptionFailure($e, $context);
            throw $e;
        }
    }

    public function hash(string $data): string
    {
        return sodium_crypto_generichash(
            $data,
            $this->keyManager->getHashKey(),
            SODIUM_CRYPTO_GENERICHASH_BYTES_MAX
        );
    }

    public function verify(string $data, string $hash): bool
    {
        return hash_equals(
            $hash,
            $this->hash($data)
        );
    }

    private function handleEncryptionFailure(\Exception $e, array $context): void
    {
        $this->metrics->increment('encryption.failures');

        $this->audit->logSecurityEvent(
            SecurityEventType::ENCRYPTION_FAILURE,
            [
                'error' => $e->getMessage(),
                'cipher' => $this->cipher,
                'context' => $context,
            ]
        );
    }

    private function handleDecryptionFailure(\Exception $e, array $context): void
    {
        $this->metrics->increment('decryption.failures');

        $this->audit->logSecurityEvent(
            SecurityEventType::DECRYPTION_FAILURE,
            [
                'error' => $e->getMessage(),
                'context' => $context,
            ]
        );
    }

    public function rotateKeys(): void
    {
        try {
            $this->keyManager->rotateKeys();
            $this->metrics->increment('key_rotation.success');

        } catch (\Exception $e) {
            $this->metrics->increment('key_rotation.failures');
            $this->audit->logSecurityEvent(
                SecurityEventType::KEY_ROTATION_FAILURE,
                ['error' => $e->getMessage()]
            );
            throw $e;
        }
    }

    public function deriveKey(string $password, string $salt): string
    {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $password,
            $salt,
            $this->options['key_iterations'],
            $this->options['memory_cost'],
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );
    }

    private function validateKeyStrength(string $key): void
    {
        if (strlen($key) < SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new EncryptionException('Key strength insufficient');
        }
    }

    public function setCipher(string $cipher): void
    {
        if (!in_array($cipher, openssl_get_cipher_methods())) {
            throw new EncryptionException('Invalid cipher method');
        }
        $this->cipher = $cipher;
    }
}
