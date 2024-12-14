```php
namespace App\Core\Security;

class EncryptionManager implements EncryptionInterface 
{
    private string $key;
    private string $cipher = 'aes-256-gcm';
    private CacheManager $cache;
    private AuditLogger $audit;

    public function encrypt(string $data, array $context = []): EncryptedData
    {
        $this->validateContext($context);
        
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($encrypted === false) {
            $this->audit->logEncryptionFailure($context);
            throw new EncryptionFailedException();
        }

        return new EncryptedData([
            'data' => base64_encode($encrypted),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'hash' => hash_hmac('sha256', $encrypted, $this->key)
        ]);
    }

    public function decrypt(EncryptedData $data, array $context = []): string
    {
        $this->validateContext($context);
        $this->verifyIntegrity($data);

        $decrypted = openssl_decrypt(
            base64_decode($data->data),
            $this->cipher,
            $this->key,
            OPENSSL_RAW_DATA,
            base64_decode($data->iv),
            base64_decode($data->tag)
        );

        if ($decrypted === false) {
            $this->audit->logDecryptionFailure($context);
            throw new DecryptionFailedException();
        }

        return $decrypted;
    }

    private function verifyIntegrity(EncryptedData $data): void
    {
        $computedHash = hash_hmac('sha256', base64_decode($data->data), $this->key);
        
        if (!hash_equals($computedHash, $data->hash)) {
            throw new DataIntegrityException();
        }
    }

    private function validateContext(array $context): void
    {
        if (!isset($context['purpose'])) {
            throw new InvalidContextException('Encryption purpose must be specified');
        }
    }
}

class DataProtector implements DataProtectionInterface
{
    private EncryptionManager $encryption;
    private ValidationService $validator;
    private MetricsCollector $metrics;

    public function protectData(array $data, string $purpose): ProtectedData
    {
        $this->metrics->track('data_protection', function() use ($data, $purpose) {
            $this->validator->validateForProtection($data);
            
            $encrypted = $this->encryption->encrypt(
                json_encode($data),
                ['purpose' => $purpose]
            );

            return new ProtectedData([
                'encrypted' => $encrypted,
                'purpose' => $purpose,
                'timestamp' => now(),
                'version' => 1
            ]);
        });
    }

    public function unprotectData(ProtectedData $protected): array
    {
        return $this->metrics->track('data_unprotection', function() use ($protected) {
            $this->validator->validateProtectedData($protected);
            
            $decrypted = $this->encryption->decrypt(
                $protected->encrypted,
                ['purpose' => $protected->purpose]
            );

            return json_decode($decrypted, true);
        });
    }
}

class IntegrityVerifier
{
    private HashManager $hash;
    private SecurityConfig $config;
    private AuditLogger $audit;

    public function verifyIntegrity(string $data, string $hash): bool
    {
        $computed = $this->hash->compute($data, $this->config->getHashAlgorithm());
        
        $result = hash_equals($computed, $hash);
        
        if (!$result) {
            $this->audit->logIntegrityFailure([
                'expected' => $hash,
                'computed' => $computed
            ]);
        }

        return $result;
    }

    public function signData(string $data): string
    {
        return $this->hash->compute($data, $this->config->getHashAlgorithm());
    }
}

class SecurityConfig
{
    private array $config;

    public function getHashAlgorithm(): string
    {
        return $this->config['hash_algorithm'] ?? 'sha256';
    }

    public function getEncryptionKey(): string
    {
        $key = $this->config['encryption_key'] ?? null;
        
        if (!$key) {
            throw new MissingKeyException();
        }

        return $key;
    }
}
```
