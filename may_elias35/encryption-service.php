<?php
namespace App\Core\Security;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Interfaces\EncryptionInterface;
use App\Core\Security\{KeyManager, IntegrityVerifier};
use App\Core\Exceptions\{EncryptionException, IntegrityException};

class EncryptionService implements EncryptionInterface
{
    private KeyManager $keyManager;
    private IntegrityVerifier $verifier;
    private AuditLogger $audit;
    private string $cipher = 'aes-256-gcm';

    public function encrypt(string $data, array $context = []): array
    {
        try {
            $key = $this->keyManager->getActiveKey();
            $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
            $tag = '';

            $encrypted = openssl_encrypt(
                $data,
                $this->cipher,
                $key->getSecret(),
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($encrypted === false) {
                throw new EncryptionException('Encryption failed');
            }

            $result = [
                'data' => base64_encode($encrypted),
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'key_id' => $key->getId(),
                'timestamp' => time(),
                'metadata' => $this->processMetadata($context)
            ];

            $result['hash'] = $this->calculateHash($result);
            return $result;

        } catch (\Throwable $e) {
            $this->handleEncryptionFailure($e, $context);
            throw new EncryptionException(
                'Encryption failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function decrypt(array $encryptedData): string
    {
        try {
            $this->verifyIntegrity($encryptedData);
            
            $key = $this->keyManager->getKey($encryptedData['key_id']);
            
            $decrypted = openssl_decrypt(
                base64_decode($encryptedData['data']),
                $this->cipher,
                $key->getSecret(),
                OPENSSL_RAW_DATA,
                base64_decode($encryptedData['iv']),
                base64_decode($encryptedData['tag'])
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed');
            }

            return $decrypted;

        } catch (\Throwable $e) {
            $this->handleDecryptionFailure($e, $encryptedData);
            throw new EncryptionException(
                'Decryption failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function verifyIntegrity(array $data): bool
    {
        $hash = $data['hash'];
        unset($data['hash']);

        if (!$this->verifier->verifyHash($data, $hash)) {
            throw new IntegrityException('Data integrity verification failed');
        }

        if (!$this->verifyTimestamp($data['timestamp'])) {
            throw new IntegrityException('Data timestamp verification failed');
        }

        return true;
    }

    public function rotateKeys(): void
    {
        DB::transaction(function() {
            $newKey = $this->keyManager->generateKey();
            $this->keyManager->setActiveKey($newKey);
            $this->audit->logKeyRotation($newKey->getId());
        });
    }

    private function calculateHash(array $data): string
    {
        return hash_hmac(
            'sha256',
            json_encode($data),
            config('app.key')
        );
    }

    private function verifyTimestamp(int $timestamp): bool
    {
        $maxAge = config('security.encryption.max_age', 86400);
        return (time() - $timestamp) <= $maxAge;
    }

    private function processMetadata(array $context): array
    {
        return [
            'encryption_version' => config('security.encryption.version'),
            'cipher' => $this->cipher,
            'context' => array_intersect_key(
                $context,
                array_flip(['user_id', 'operation', 'resource'])
            )
        ];
    }

    private function handleEncryptionFailure(\Throwable $e, array $context): void
    {
        $this->audit->logEncryptionFailure($e, $context);
        
        if ($this->isCriticalFailure($e)) {
            $this->audit->notifySecurityTeam($e, $context);
        }
    }

    private function handleDecryptionFailure(\Throwable $e, array $data): void
    {
        $this->audit->logDecryptionFailure($e, $data);
        
        if ($this->isKeyCompromised($e)) {
            $this->keyManager->revokeKey($data['key_id']);
            $this->audit->notifySecurityTeam($e, $data);
        }
    }
}
