```php
<?php
namespace App\Core\Config;

class SystemBootstrap implements BootstrapInterface 
{
    private SecurityManager $security;
    private ConfigLoader $config;
    private SystemValidator $validator;
    private AuditLogger $logger;

    public function bootstrap(): void 
    {
        try {
            $this->validator->validateEnvironment();
            $this->initializeSecurity();
            $this->loadConfigurations();
            $this->initializeServices();
            $this->validateSystem();
        } catch (\Exception $e) {
            $this->handleBootstrapFailure($e);
            throw new BootstrapException('System bootstrap failed', 0, $e);
        }
    }

    private function initializeSecurity(): void 
    {
        $this->security->initializeCore();
        $this->security->validateSecurityConfig();
        $this->security->enableCoreSecurity();
    }

    private function loadConfigurations(): void 
    {
        $configs = $this->config->loadSecureConfigs();
        foreach ($configs as $key => $config) {
            $this->validateConfig($key, $config);
            $this->applyConfig($key, $config);
        }
    }

    private function validateConfig(string $key, array $config): void 
    {
        if (!$this->validator->validateConfig($key, $config)) {
            throw new ConfigurationException("Invalid configuration: $key");
        }
    }
}

class ConfigurationManager implements ConfigurationInterface 
{
    private SecurityManager $security;
    private Cache $cache;
    private array $configs = [];

    public function get(string $key, $default = null) 
    {
        try {
            if (isset($this->configs[$key])) {
                return $this->configs[$key];
            }

            $value = $this->cache->remember("config:$key", function() use ($key) {
                return $this->loadConfig($key);
            });

            $this->configs[$key] = $value;
            return $value;
        } catch (\Exception $e) {
            $this->handleConfigError($e, $key);
            return $default;
        }
    }

    public function set(string $key, $value): void 
    {
        try {
            $this->validateConfigValue($key, $value);
            $this->configs[$key] = $value;
            $this->cache->put("config:$key", $value);
            $this->security->auditConfigChange($key, $value);
        } catch (\Exception $e) {
            $this->handleConfigError($e, $key);
            throw new ConfigurationException("Failed to set config: $key", 0, $e);
        }
    }

    private function loadConfig(string $key): mixed 
    {
        $config = $this->security->loadSecureConfig($key);
        $this->validateConfigValue($key, $config);
        return $config;
    }
}

class EnvironmentManager implements EnvironmentInterface 
{
    private SecurityManager $security;
    private array $requiredVars;

    public function validate(): void 
    {
        foreach ($this->requiredVars as $var) {
            if (!$this->isValidEnvironmentVar($var)) {
                throw new EnvironmentException("Missing or invalid environment variable: $var");
            }
        }
    }

    public function get(string $key, $default = null): mixed 
    {
        try {
            $value = $this->security->getEnvironmentVar($key);
            return $value ?? $default;
        } catch (\Exception $e) {
            $this->handleEnvError($e, $key);
            return $default;
        }
    }

    private function isValidEnvironmentVar(string $var): bool 
    {
        $value = $this->security->getEnvironmentVar($var);
        return $value !== null && $this->validateVarFormat($var, $value);
    }

    private function validateVarFormat(string $var, string $value): bool 
    {
        return match ($var) {
            'APP_KEY' => $this->validateAppKey($value),
            'APP_ENV' => in_array($value, ['local', 'staging', 'production']),
            'DB_CONNECTION' => $this->validateDbConnection($value),
            default => true
        };
    }
}

interface BootstrapInterface 
{
    public function bootstrap(): void;
}

interface ConfigurationInterface 
{
    public function get(string $key, $default = null);
    public function set(string $key, $value): void;
}

interface EnvironmentInterface 
{
    public function validate(): void;
    public function get(string $key, $default = null): mixed;
}
```
