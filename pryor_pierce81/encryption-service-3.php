<?php

namespace App\Core\Security;

use App\Core\Interfaces\EncryptionInterface;
use App\Core\Services\{KeyManagementService, AuditService};
use App\Core\Events\{EncryptionKeyRotated, SecurityEventDetected};
use App\Core\Exceptions\{EncryptionException, SecurityException};
use Illuminate\Support\Facades\{Cache, Log, Event};

class EncryptionService implements EncryptionInterface
{
    private KeyManagementService $keyManager;
    private AuditService $audit;
    private array $config;
    private string $defaultCipher = 'AES-256-GCM';

    public function __construct(
        KeyManagementService $keyManager,
        AuditService $audit,
        array $config
    ) {
        $this->keyManager = $keyManager;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function encrypt(string $data, array $context = []): string
    {
        try {
            $key = $this->keyManager->getCurrentKey();
            $iv = random_bytes(16);
            
            $encrypted = openssl_encrypt(
                $data,
                $this->defaultCipher,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            $result = base64_encode(
                json_encode([
                    'data' => base64_encode($encrypted),
                    'iv' => base64_encode($iv),
                    'tag' => base64_encode($tag),
                    'key_id' => $this->keyManager->getCurrentKeyId(),
                    'timestamp' => time(),
                    'context' => $context
                ])
            );

            $this->audit->logEncryption('encrypt', $context);
            return $result;

        } catch (\Exception $e) {
            $this->handleEncryptionFailure('encrypt', $e, $context);
            throw new EncryptionException('Encryption failed', 0, $e);
        }
    }

    public function decrypt(string $encryptedData, array $context = []): string
    {
        try {
            $data = json_decode(base64_decode($encryptedData), true);
            
            if (!$this->validateEncryptedData($data)) {
                throw new EncryptionException('Invalid encrypted data format');
            }

            $key = $this->keyManager->getKeyById($data['key_id']);
            
            $decrypted = openssl_decrypt(
                base64_decode($data['data']),
                $this->defaultCipher,
                $key,
                OPENSSL_RAW_DATA,
                base64_decode($data['iv']),
                base64_decode($data['tag'])
            );

            if ($decrypted === false) {
                throw new EncryptionException('Decryption failed - data integrity check failed');
            }

            $this->audit->logEncryption('decrypt', $context);
            return $decrypted;

        } catch (\Exception $e) {
            $this->handleEncryptionFailure('decrypt', $e, $context);
            throw new EncryptionException('Decryption failed', 0, $e);
        }
    }

    public function rotateKeys(): void
    {
        try {
            $oldKeyId = $this->keyManager->getCurrentKeyId();
            $newKeyId = $this->keyManager->rotateKeys();

            $this->reEncryptData($oldKeyId, $newKeyId);
            
            Event::dispatch(new EncryptionKeyRotated($newKeyId));
            $this->audit->logKeyRotation($oldKeyId, $newKeyId);

        } catch (\Exception $e) {
            $this->handleKeyRotationFailure($e);
            throw new SecurityException('Key rotation failed', 0, $e);
        }
    }

    public function verifyIntegrity(string $encryptedData): bool
    {
        try {
            $data = json_decode(base64_decode($encryptedData), true);
            
            if (!$this->validateEncryptedData($data)) {
                return false;
            }

            return $this->verifyDataIntegrity($data);

        } catch (\Exception $e) {
            $this->handleIntegrityCheckFailure($e);
            return false;
        }
    }

    protected function validateEncryptedData(array $data): bool
    {
        return isset($data['data']) &&
               isset($data['iv']) &&
               isset($data['tag']) &&
               isset($data['key_id']) &&
               isset($data['timestamp']);
    }

    protected function verifyDataIntegrity(array $data): bool
    {
        if (!$this->keyManager->verifyKeyId($data['key_id'])) {
            return false;
        }

        if (time() - $data['timestamp'] > $this->config['max_data_age']) {
            Event::dispatch(new SecurityEventDetected('data_expiration', $data));
            return false;
        }

        return true;
    }

    protected function reEncryptData(string $oldKeyId, string $newKeyId): void
    {
        $tables = $this->config['encrypted_tables'];
        
        foreach ($tables as $table => $columns) {
            $this->reEncryptTableData($table, $columns, $oldKeyId, $newKeyId);
        }
    }

    protected function reEncryptTableData(
        string $table,
        array $columns,
        string $oldKeyId,
        string $newKeyId
    ): void {
        $records = DB::table($table)->get();
        
        foreach ($records as $record) {
            foreach ($columns as $column) {
                if (isset($record->$column)) {
                    $decrypted = $this->decrypt($record->$column);
                    $reEncrypted = $this->encrypt($decrypted);
                    
                    DB::table($table)
                        ->where('id', $record->id)
                        ->update([$column => $reEncrypted]);
                }
            }
        }
    }

    protected function handleEncryptionFailure(
        string $operation,
        \Exception $e,
        array $context
    ): void {
        $this->audit->logSecurityFailure(
            "encryption_{$operation}_failed",
            [
                'error' => $e->getMessage(),
                'context' => $context
            ]
        );

        if ($this->isSecurityThreat($e)) {
            Event::dispatch(new SecurityEventDetected(
                'encryption_security_threat',
                ['error' => $e->getMessage()]
            ));
        }
    }

    protected function handleKeyRotationFailure(\Exception $e): void
    {
        $this->audit->logSecurityFailure('key_rotation_failed', [
            'error' => $e->getMessage()
        ]);

        Log::emergency('Encryption key rotation failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function isSecurityThreat(\Exception $e): bool
    {
        return $e instanceof SecurityException ||
               strpos($e->getMessage(), 'integrity') !== false ||
               strpos($e->getMessage(), 'tamper') !== false;
    }
}
