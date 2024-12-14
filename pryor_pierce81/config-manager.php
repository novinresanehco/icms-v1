<?php

namespace App\Core\Config;

use Illuminate\Support\Facades\{Cache, File, Crypt};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService};
use App\Core\Interfaces\ConfigManagerInterface;
use App\Core\Exceptions\{ConfigException, ValidationException};

class ConfigManager implements ConfigManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $loadedConfigs = [];
    private array $environmentConfigs = [];
    private string $configPath;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        string $configPath
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->configPath = $configPath;
    }

    public function getConfig(string $key, string $environment = null): mixed
    {
        return $this->security->executeSecureOperation(
            fn() => $this->retrieveConfig($key, $environment),
            new SecurityContext('config.get', ['key' => $key])
        );
    }

    public function setConfig(string $key, mixed $value, string $environment = null): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->storeConfig($key, $value, $environment),
            new SecurityContext('config.set', ['key' => $key])
        );
    }

    public function loadEnvironmentConfig(string $environment): void
    {
        $this->security->executeSecureOperation(
            fn() => $this->processEnvironmentLoad($environment),
            new SecurityContext('config.loadEnvironment', ['environment' => $environment])
        );
    }

    protected function retrieveConfig(string $key, ?string $environment): mixed
    {
        try {
            $cacheKey = $this->generateCacheKey($key, $environment);
            
            if ($cached = $this->getFromCache($cacheKey)) {
                return $cached;
            }

            $config = $this->loadConfigValue($key, $environment);
            $this->validateConfigValue($config);
            
            $this->cacheConfig($cacheKey, $config);
            $this->audit->logConfigAccess($key, $environment);
            
            return $config;

        } catch (\Exception $e) {
            $this->handleConfigFailure('retrieve', $key, $e);
            throw new ConfigException('Configuration retrieval failed: ' . $e->getMessage());
        }
    }

    protected function storeConfig(string $key, mixed $value, ?string $environment): bool
    {
        try {
            $this->validateConfigKey($key);
            $this->validateConfigValue($value);
            
            $path = $this->getConfigPath($key, $environment);
            $encrypted = $this->encryptSensitiveData($value);
            
            File::put($path, serialize($encrypted));
            $this->clearConfigCache($key, $environment);
            
            $this->audit->logConfigChange($key, $environment);
            return true;

        } catch (\Exception $e) {
            $this->handleConfigFailure('store', $key, $e);
            throw new ConfigException('Configuration storage failed: ' . $e->getMessage());
        }
    }

    protected function processEnvironmentLoad(string $environment): void
    {
        try {
            $this->validateEnvironment($environment);
            
            $configFiles = $this->scanEnvironmentConfigs($environment);
            foreach ($configFiles as $file) {
                $this->loadEnvironmentFile($file, $environment);
            }
            
            $this->audit->logEnvironmentLoad($environment);

        } catch (\Exception $e) {
            $this->handleConfigFailure('environment_load', $environment, $e);
            throw new ConfigException('Environment configuration load failed: ' . $e->getMessage());
        }
    }

    protected function validateConfigKey(string $key): void
    {
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key)) {
            throw new ValidationException('Invalid configuration key format');
        }
    }

    protected function validateConfigValue(mixed $value): void
    {
        if (!$this->validator->validateConfigValue($value)) {
            throw new ValidationException('Invalid configuration value');
        }
    }

    protected function validateEnvironment(string $environment): void
    {
        if (!in_array($environment, ['local', 'development', 'staging', 'production'])) {
            throw new ValidationException('Invalid environment specified');
        }
    }

    protected function generateCacheKey(string $key, ?string $environment): string
    {
        return hash('xxh3', serialize([
            'key' => $key,
            'environment' => $environment ?? 'default',
            'version' => $this->getConfigVersion()
        ]));
    }

    protected function getFromCache(string $cacheKey): mixed
    {
        if (isset($this->loadedConfigs[$cacheKey])) {
            return $this->loadedConfigs[$cacheKey];
        }

        return Cache::get($cacheKey);
    }

    protected function cacheConfig(string $cacheKey, mixed $value): void
    {
        $this->loadedConfigs[$cacheKey] = $value;
        Cache::put($cacheKey, $value, $this->getCacheDuration());
    }

    protected function clearConfigCache(string $key, ?string $environment): void
    {
        $cacheKey = $this->generateCacheKey($key, $environment);
        unset($this->loadedConfigs[$cacheKey]);
        Cache::forget($cacheKey);
    }

    protected function loadConfigValue(string $key, ?string $environment): mixed
    {
        $path = $this->getConfigPath($key, $environment);
        
        if (!File::exists($path)) {
            throw new ConfigException('Configuration not found');
        }

        $encrypted = unserialize(File::get($path));
        return $this->decryptSensitiveData($encrypted);
    }

    protected function encryptSensitiveData(mixed $value): mixed
    {
        if ($this->isSensitiveData($value)) {
            return Crypt::encrypt($value);
        }

        if (is_array($value)) {
            return array_map([$this, 'encryptSensitiveData'], $value);
        }

        return $value;
    }

    protected function decryptSensitiveData(mixed $value): mixed
    {
        if (is_string($value) && $this->isEncrypted($value)) {
            return Crypt::decrypt($value);
        }

        if (is_array($value)) {
            return array_map([$this, 'decryptSensitiveData'], $value);
        }

        return $value;
    }

    protected function isSensitiveData(mixed $value): bool
    {
        $sensitiveKeys = ['password', 'secret', 'key', 'token', 'credential'];
        
        if (is_array($value)) {
            $keys = array_keys($value);
            return (bool) array_intersect($keys, $sensitiveKeys);
        }

        return false;
    }

    protected function isEncrypted(string $value): bool
    {
        return strpos($value, 'eyJ') === 0;
    }

    protected function scanEnvironmentConfigs(string $environment): array
    {
        $path = $this->getEnvironmentPath($environment);
        return File::glob($path . '/*.php');
    }

    protected function loadEnvironmentFile(string $file, string $environment): void
    {
        $config = require $file;
        $key = pathinfo($file, PATHINFO_FILENAME);
        
        $this->environmentConfigs[$environment][$key] = $config;
    }
}
