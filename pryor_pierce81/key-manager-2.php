<?php

namespace App\Core\Security\Encryption;

use App\Core\Exception\KeyManagementException;
use App\Core\Security\SecurityManagerInterface;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\DB;

class KeyManagementService implements KeyManagementInterface
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
    }

    public function generateKey(array $options = []): Key
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('keys:generate', [
                'operation_id' => $operationId
            ]);

            $key = $this->createKey($options);
            $this->storeKey($key);
            
            $this->logKeyOperation($operationId, 'generate', $key->getId());

            DB::commit();
            return $key;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleKeyFailure($operationId, 'generate', $e);
            throw new KeyManagementException('Key generation failed', 0, $e);
        }
    }

    public function rotateKey(string $keyId): Key
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('keys:rotate', [
                'operation_id' => $operationId,
                'key_id' => $keyId
            ]);

            $oldKey = $this->getKey($keyId);
            $newKey = $this->createKey($oldKey->getOptions());
            
            $this->archiveKey($oldKey);
            $this->storeKey($newKey);
            
            $this->logKeyOperation($operationId, 'rotate', $keyId);

            DB::commit();
            return $newKey;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleKeyFailure($operationId, 'rotate', $e);
            throw new KeyManagementException('Key rotation failed', 0, $e);
        }
    }

    public function revokeKey(string $keyId): void
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('keys:revoke', [
                'operation_id' => $operationId,
                'key_id' => $keyId
            ]);

            $key = $this->getKey($keyId);
            $this->markKeyRevoked($key);
            
            $this->logKeyOperation($operationId, 'revoke', $keyId);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleKeyFailure($operationId, 'revoke', $e);
            throw new KeyManagementException('Key revocation failed', 0, $e);
        }
    }

    public function verifyKey(string $keyId): bool
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('keys:verify', [
                'operation_id' => $operationId,
                'key_id' => $keyId
            ]);

            $key = $this->getKey($keyId);
            return $this->validateKey($key);

        } catch (\Exception $e) {
            $this->handleKeyFailure($operationId, 'verify', $e);
            throw new KeyManagementException('Key verification failed', 0, $e);
        }
    }

    private function createKey(array $options): Key
    {
        $keyData = random_bytes($this->config['key_length']);
        
        return new Key([
            'id' => $this->generateKeyId(),
            'data' => $keyData,
            'type' => $options['type'] ?? 'symmetric',
            'algorithm' => $options['algorithm'] ?? $this->config['default_algorithm'],
            'created_at' => now(),
            'expires_at' => $this->calculateExpiry($options),
            'status' => 'active'
        ]);
    }

    private function storeKey(Key $key): void
    {
        $encryptedData = $this->encryptKeyData($key->getData());
        
        DB::table('encryption_keys')->insert([
            'id' => $key->getId(),
            'encrypted_data' => $encryptedData,
            'type' => $key->getType(),
            'algorithm' => $key->getAlgorithm(),
            'created_at' => $key->getCreatedAt(),
            'expires_at' => $key->getExpiresAt(),
            'status' => $key->getStatus()
        ]);

        $this->keyCache[$key->getId()] = $key;
    }

    private function getKey(string $keyId): Key
    {
        if (isset($this->keyCache[$keyId])) {
            return $this->keyCache[$keyId];
        }

        $keyData = DB::table('encryption_keys')
            ->where('id', $keyId)
            ->first();

        if (!$keyData) {
            throw new KeyManagementException('Key not found');
        }

        $key = new Key([
            'id' => $keyData->id,
            'data' => $this->decryptKeyData($keyData->encrypted_data),
            'type' => $keyData->type,
            'algorithm' => $keyData->algorithm,
            'created_at' => $keyData->created_at,
            'expires_at' => $keyData->expires