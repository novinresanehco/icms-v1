```php
namespace App\Core\Config;

use App\Core\Security\SecurityManager;
use App\Core\Encryption\EncryptionService;
use App\Core\Validation\ValidationService;

class ConfigurationManager
{
    private SecurityManager $security;
    private EncryptionService $encryption;
    private ValidationService $validator;
    
    private array $configCache = [];
    private array $secureKeys = [];

    public function loadConfiguration(): void
    {
        DB::beginTransaction();
        
        try {
            // Load encrypted configuration
            $this->loadEncryptedConfig();
            
            // Validate configuration
            $this->validateConfiguration();
            
            // Initialize security settings
            $this->initializeSecurity();
            
            DB::commit();
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleConfigurationFailure($e);
            throw $e;
        }
    }

    public function get(string $key, $default = null): mixed
    {
        if (isset($this->secureKeys[$key])) {
            return $this->getSecureConfig($key, $default);
        }
        
        return $this->configCache[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        DB::beginTransaction();
        
        try {
            // Validate new value
            $this->validator->validateConfigValue($key, $value);
            
            // Store configuration
            if (isset($this->secureKeys[$key])) {
                $this->setSecureConfig($key, $value);
            } else {
                $this->configCache[$key] = $value;
            }
            
            // Update database
            $this->persistConfig($key, $value);
            
            DB::commit();
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleConfigurationFailure($e);
            throw $e;
        }
    }

    private function loadEncryptedConfig(): void
    {
        $encryptedConfig = DB::table('configurations')
            ->where('encrypted', true)
            ->get();
            
        foreach ($encryptedConfig as $config) {
            $this->secureKeys[$config->key] = true;
            $this->configCache[$config->key] = $this->encryption->decrypt($config->value);
        }
    }

    private function validateConfiguration(): void
    {
        foreach ($this->configCache as $key => $value) {
            if (!$this->validator->validateConfigValue($key, $value)) {
                throw new ConfigurationException("Invalid configuration value for: {$key}");
            }
        }
    }

    private function initializeSecurity(): void
    {
        $this->security->initializeWithConfig($this->configCache);
    }

    private function getSecureConfig(string $key, $default = null): mixed
    {
        try {
            return $this->encryption->decrypt($this->configCache[$key] ?? $default);
        } catch (\Throwable $e) {
            $this->handleConfigurationFailure($e);
            return $default;
        }
    }

    private function setSecureConfig(string $key, mixed $value): void
    {
        $this->configCache[$key] = $this->encryption->encrypt($value);
        $this->secureKeys[$key] = true;
    }

    private function persistConfig(string $key, mixed $value): void
    {
        $encrypted = isset($this->secureKeys[$key]);
        $storedValue = $encrypted ? $this->encryption->encrypt($value) : $value;
        
        DB::table('configurations')->updateOrInsert(
            ['key' => $key],
            [
                'value' => $storedValue,
                'encrypted' => $encrypted,
                'updated_at' => now()
            ]
        );
    }

    private function handleConfigurationFailure(\Throwable $e): void
    {
        $this->security->logError('Configuration failure', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->security->notifyAdministrators('configuration_failure', [
            'error' => $e->getMessage()
        ]);
    }
}
```
