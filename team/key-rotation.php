```php
namespace App\Core\Security;

use App\Core\Monitoring\MonitoringService;
use Illuminate\Support\Facades\DB;

class KeyRotationService
{
    private KeyManager $keyManager;
    private MonitoringService $monitor;
    private EncryptionService $encryption;
    
    private const ROTATION_INTERVAL = 86400; // 24 hours
    private const MAX_KEY_AGE = 604800; // 7 days

    public function rotateKeys(): void
    {
        $operationId = $this->monitor->startOperation('key_rotation');
        
        DB::beginTransaction();
        
        try {
            // Check for expired keys
            $this->checkExpiredKeys();
            
            // Generate new key if needed
            $this->rotateActiveKey();
            
            // Re-encrypt critical data
            $this->reencryptCriticalData();
            
            // Archive old keys
            $this->archiveOldKeys();
            
            DB::commit();
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleRotationFailure($e);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function checkExpiredKeys(): void
    {
        $expiredKeys = $this->keyManager->getExpiredKeys(self::MAX_KEY_AGE);
        
        foreach ($expiredKeys as $key) {
            $this->reencryptKeyData($key);
            $this->keyManager->archiveKey($key);
        }
    }

    private function rotateActiveKey(): void
    {
        $activeKey = $this->keyManager->getActiveKey();
        
        if ($this->shouldRotateKey($activeKey)) {
            $this->keyManager->generateNewKey();
            $this->reencryptKeyData($activeKey);
            $this->keyManager->deactivateKey($activeKey);
        }
    }

    private function reencryptCriticalData(): void
    {
        // Re-encrypt configuration
        $this->reencryptConfigurations();
        
        // Re-encrypt credentials
        $this->reencryptCredentials();
        
        // Re-encrypt sensitive data
        $this->reencryptSensitiveData();
    }

    private function reencryptConfigurations(): void
    {
        $configs = DB::table('configurations')
            ->where('encrypted', true)
            ->get();
            
        foreach ($configs as $config) {
            $decrypted = $this->encryption->decrypt([
                'data' => $config->value,
                'iv' => $config->iv,
                'tag' => $config->tag,
                'key_id' => $config->key_id
            ]);
            
            $encrypted = $this->encryption->encrypt($decrypted);
            
            DB::table('configurations')
                ->where('id', $config->id)
                ->update([
                    'value' => $encrypted['data'],
                    'iv' => $encrypted['iv'],
                    'tag' => $encrypted['tag'],
                    'key_id' => $encrypted['key_id']
                ]);
        }
    }

    private function reencryptCredentials(): void
    {
        $credentials = DB::table('credentials')
            ->where('encrypted', true)
            ->get();
            
        foreach ($credentials as $credential) {
            $decrypted = $this->encryption->decrypt([
                'data' => $credential->value,
                'iv' => $credential->iv,
                'tag' => $credential->tag,
                'key_id' => $credential->key_id
            ]);
            
            $encrypted = $this->encryption->encrypt($decrypted);
            
            DB::table('credentials')
                ->where('id', $credential->id)
                ->update([
                    'value' => $encrypted['data'],
                    'iv' => $encrypted['iv'],
                    'tag' => $encrypted['tag'],
                    'key_id' => $encrypted['key_id']
                ]);
        }
    }

    private function shouldRotateKey(EncryptionKey $key): bool
    {
        return time() - $key->getCreatedAt()->timestamp >= self::ROTATION_INTERVAL;
    }

    private function archiveOldKeys(): void
    {
        $oldKeys = $this->keyManager->getInactiveKeys(self::MAX_KEY_AGE * 2);
        
        foreach ($oldKeys as $key) {
            $this->keyManager->archiveKey($key);
        }
    }

    private function handleRotationFailure(\Throwable $e): void
    {
        $this->monitor->recordFailure('key_rotation', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Notify administrators of rotation failure
        $this->monitor->notifyAdministrators('key_rotation_failure', [
            'error' => $e->getMessage()
        ]);
    }
}
```
