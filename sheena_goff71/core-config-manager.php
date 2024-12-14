<?php

namespace App\Core\Security;

class ConfigurationManager implements ConfigurationInterface
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private Cache $cache;
    private string $configPath;

    public function __construct(
        EncryptionService $encryption,
        ValidationService $validator,
        AuditLogger $auditLogger,
        Cache $cache,
        string $configPath
    ) {
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
        $this->configPath = $configPath;
    }

    public function loadSecureConfig(): void
    {
        try {
            // Load configuration files
            $config = $this->loadConfigFiles();

            // Validate configuration
            $this->validateConfiguration($config);

            // Decrypt sensitive values
            $config = $this->decryptSensitiveValues($config);

            // Cache validated configuration
            $this->cacheConfiguration($config);

            // Log successful load
            $this->auditLogger->logSecurityEvent(
                new SecurityEvent(
                    SecurityEventType::CONFIG_LOAD,
                    'Configuration loaded successfully',
                    SecurityLevel::INFO
                )
            );

        } catch (\Exception $e) {
            $this->handleConfigurationError($e);
            throw new ConfigurationException('Failed to load configuration', 0, $e);
        }
    }

    public function get(string $key, $default = null)
    {
        try {
            $value = $this->cache->remember(
                "config:{$key}",
                3600,
                fn() => $this->loadConfigValue($key)
            );

            return $value ?? $default;

        } catch (\Exception $e) {
            $this->handleConfigurationError($e);
            return $default;
        }
    }

    public function set(string $key, $value): void
    {
        DB::beginTransaction();

        try {
            // Validate new value
            $this->validateConfigValue($key, $value);

            // Encrypt if sensitive
            if ($this->isSensitiveKey($key)) {
                $value = $this->encryption->encrypt($value);
            }

            // Update configuration
            $this->updateConfigValue($key, $value);

            // Clear cache
            $this->cache->forget("config:{$key}");

            // Log change
            $this->auditLogger->logSecurityEvent(
                new SecurityEvent(
                    SecurityEventType::CONFIG_CHANGE,
                    "Configuration updated: {$key}",
                    SecurityLevel::WARNING
                )
            );

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleConfigurationError($e);
            throw new ConfigurationException("Failed to update configuration: {$key}", 0, $e);
        }
    }

    private function loadConfigFiles(): array
    {
        $config = [];
        $files = glob($this->configPath . '/*.php');

        foreach ($files as $file) {
            $key = basename($file, '.php');
            $data = require $file;

            if (!is_array($data)) {
                throw new ConfigurationException("Invalid configuration file: {$key}");
            }

            $config[$key] = $data;
        }

        return $config;
    }

    private function validateConfiguration(array $config): void
    {
        foreach ($config as $section => $data) {
            $rules = $this->getValidationRules($section);
            
            if (!$this->validator->validate($data, $rules)) {
                throw new ConfigurationException("Invalid configuration section: {$section}");
            }
        }
    }

    private function decryptSensitiveValues(array $config): array
    {
        array_walk_recursive($config, function(&$value, $key) {
            if ($this->isSensitiveKey($key) && !empty($value)) {
                $value = $this->encryption->decrypt($value);
            }
        });

        return $config;
    }

    private function cacheConfiguration(array $config): void
    {
        foreach ($config as $key => $value) {
            $this->cache->put("config:{$key}", $value, 3600);
        }
    }

    private function loadConfigValue(string $key)
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);
        
        if (!file_exists($this->configPath . "/{$file}.php")) {
            throw new ConfigurationException("Configuration file not found: {$file}");
        }

        $config = require $this->configPath . "/{$file}.php";
        
        foreach ($parts as $part) {
            if (!isset($config[$part])) {
                return null;
            }
            $config = $config[$part];
        }

        return $this->isSensitiveKey($key) ? $this->encryption->decrypt($config) : $config;
    }

    private function validateConfigValue(string $key, $value): void
    {
        $rules = $this->getValidationRules($key);
        
        if (!$this->validator->validate(['value' => $value], ['value' => $rules])) {
            throw new ValidationException("Invalid configuration value for {$key}");
        }
    }

    private function updateConfigValue(string $key, $value): void
    {
        $parts = explode('.', $key);
        $file = array_shift($parts);
        $path = $this->configPath . "/{$file}.php";

        if (!file_exists($path)) {
            throw new ConfigurationException("Configuration file not found: {$file}");
        }

        $config = require $path;
        $current = &$config;

        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $current[$part] = $value;
            } else {
                if (!isset($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }

        $this->writeConfigFile($path, $config);
    }

    private function writeConfigFile(string $path, array $config): void
    {
        $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        
        if (file_put_contents($path, $content) === false) {
            throw new ConfigurationException("Failed to write configuration file: {$path}");
        }
    }

    private function isSensitiveKey(string $key): bool
    {
        $sensitiveKeys = [
            'app.key',
            'database.password',
            'mail.password',
            'services.*.key',
            'services.*.secret'
        ];

        foreach ($sensitiveKeys as $pattern) {
            if (fnmatch($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    private function handleConfigurationError(\Exception $e): void
    {
        $this->auditLogger->logSecurityEvent(
            new SecurityEvent(
                SecurityEventType::CONFIG_ERROR,
                $e->getMessage(),
                SecurityLevel::ERROR,
                ['trace' => $e->getTraceAsString()]
            )
        );
    }
}

interface ConfigurationInterface
{
    public function loadSecureConfig(): void;
    public function get(string $key, $default = null);
    public function set(string $key, $value): void;
}
