<?php

namespace App\Core\Security;

use App\Core\Interfaces\KeyManagementInterface;
use App\Core\Services\AuditService;
use App\Core\Exceptions\{SecurityException, KeyManagementException};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class KeyManagementService implements KeyManagementInterface
{
    private const KEY_LENGTH = 32;
    private const CACHE_PREFIX = 'encryption_key:';
    private const ACTIVE_KEY_ID = 'active_key_id';
    
    protected AuditService $auditService;
    
    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    public function getCurrentKey(): string
    {
        try {
            $activeKeyId = $this->getActiveKeyId();
            $key = $this->retrieveKey($activeKeyId);
            
            if (!$key) {
                throw new KeyManagementException('Active encryption key not found');
            }
            
            return $key;
        } catch (\Exception $e) {
            $this->auditService->logSecurityEvent('key_retrieval_failure', [
                'error' => $e->getMessage()
            ]);
            throw new KeyManagementException('Failed to retrieve current key: ' . $e->getMessage(), 0, $e);
        }
    }

    public function rotateKeys(): void
    {
        DB::beginTransaction();
        
        try {
            // Generate new key
            $newKey = $this->generateKey();
            $newKeyId = uniqid('key_', true);
            
            // Store new key
            $this->storeKey($newKeyId, $newKey);
            
            // Update active key pointer
            $oldKeyId = $this->getActiveKeyId();
            $this->setActiveKeyId($newKeyId);
            
            // Archive old key but keep it for existing data
            $this->archiveKey($oldKeyId);
            
            DB::commit();
            
            $this->auditService->logSecurityEvent('key_rotation_success');
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->auditService->logSecurityEvent('key_rotation_failure', [
                'error' => $e->getMessage()
            ]);
            
            throw new KeyManagementException('Key rotation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function generateKey(): string
    {
        try {
            return bin2hex(random_bytes(self::KEY_LENGTH));
        } catch (\Exception $e) {
            throw new SecurityException('Failed to generate secure key');
        }
    }

    protected function storeKey(string $keyId, string $key): void
    {
        $encrypted = $this->encryptKey($key);
        
        DB::table('encryption_keys')->insert([
            'id' => $keyId,
            'key_data' => $encrypted,
            'created_at' => now(),
            'status' => 'active'
        ]);

        Cache::put(self::CACHE_PREFIX . $keyId, $key, now()->addHours(24));
    }

    protected function retrieveKey(string $keyId): ?string
    {
        // Try cache first
        $key = Cache::get(self::CACHE_PREFIX . $keyId);
        if ($key) {
            return $key;
        }

        // Fallback to database
        $keyData = DB::table('encryption_keys')
            ->where('id', $keyId)
            ->where('status', '!=', 'deleted')
            ->first();

        if (!$keyData) {
            return null;
        }

        $key = $this->decryptKey($keyData->key_data);
        
        // Cache for future use
        Cache::put(self::CACHE_PREFIX . $keyId, $key, now()->addHours(24));
        
        return $key;
    }

    protected function getActiveKeyId(): string
    {
        $activeKeyId = Cache::get(self::ACTIVE_KEY_ID);
        
        if (!$activeKeyId) {
            $activeKey = DB::table('encryption_keys')
                ->where('status', 'active')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$activeKey) {
                throw new SecurityException('No active encryption key found');
            }

            $activeKeyId = $activeKey->id;
            Cache::put(self::ACTIVE_KEY_ID, $activeKeyId, now()->addHours(24));
        }

        return $activeKeyId;
    }

    protected function setActiveKeyId(string $keyId): void
    {
        Cache::put(self::ACTIVE_KEY_ID, $keyId, now()->addHours(24));
    }

    protected function archiveKey(string $keyId): void
    {
        DB::table('encryption_keys')
            ->where('id', $keyId)
            ->update([
                'status' => 'archived',
                'archived_at' => now()
            ]);

        Cache::forget(self::CACHE_PREFIX . $keyId);
    }

    protected function encryptKey(string $key): string
    {
        // Use a separate encryption mechanism for key storage
        // This should use a hardware security module or similar in production
        return encrypt($key);
    }

    protected function decryptKey(string $encryptedKey): string
    {
        return decrypt($encryptedKey);
    }
}
