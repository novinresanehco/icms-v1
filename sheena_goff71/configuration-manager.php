<?php

namespace App\Core\Config;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Exceptions\ConfigurationException;

class ConfigurationManager implements ConfigurationInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private array $loadedConfigs = [];
    private array $schemas = [];

    private const CRITICAL_CONFIGS = [
        'security',
        'database',
        'cache',
        'monitoring'
    ];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->cache = $cache;
    }

    public function loadConfig(string $name): array
    {
        // Check cache first
        if ($cached = $this->cache->get("config:$name")) {
            return $cached;
        }

        try {
            // Load configuration
            $config = $this->loadConfigFile($name);
            
            // Validate critical config
            if (in_array($name, self::CRITICAL_CONFIGS)) {
                $this->validateCriticalConfig($name, $config);
            }
            
            // Process environment variables
            $config = $this->processEnvironmentVariables($config);
            
            // Cache configuration
            $this->cache->set("config:$name", $config);
            
            // Store in memory
            $this->loadedConfigs[$name] = $config;
            
            return $config;
            
        } catch (\Throwable $e) {
            throw new ConfigurationException(
                "Failed to load configuration '$name': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function validateConfig(array $config, array $schema): bool
    {
        foreach ($schema as $key => $rules) {
            if (!$this->validateConfigValue($config[$key] ?? null, $rules)) {
                return false;
            }
        }

        return true;
    }

    public function updateConfig(string $name, array $values): bool
    {
        return $this->security->validateSecureOperation(function() use ($name, $values) {
            // Load current config
            $current = $this->loadConfig($name);
            
            // Validate new values
            if (!$this->validateConfigChanges($name, $current, $values)) {
                return false;
            }
            
            // Create backup
            $this->backupConfig($name);
            
            // Update configuration
            $updated = array_merge($current, $values);
            
            // Save configuration
            $this->saveConfigFile($name, $updated);
            
            // Clear cache
            $this->cache->delete("config:$name");
            
            // Update loaded configs
            $this->loadedConfigs[$name] = $updated;
            
            return true;
        }, new SecurityContext(['action' => 'update_config']));
    }

    public function backupConfig(string $name): string
    {
        $backupId = uniqid("config_backup_{$name}_", true);
        
        try {
            // Get current config
            $config = $this->loadedConfigs[$name] ?? $this->loadConfig($name);
            
            // Create backup
            $backup = [
                'id' => $backupId,
                'name' => $name,
                'data' => $config,
                'timestamp' => time(),
                'checksum' => $this->generateConfigChecksum($config)
            ];
            
            // Store backup
            $this->storeConfigBackup($backup);
            
            return $backupId;
            
        } catch (\Throwable $e) {
            throw new ConfigurationException(
                "Failed to backup configuration '$name': " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function loadConfigFile(string $name): array
    {
        $path = $this->getConfigPath($name);
        
        if (!file_exists($path)) {
            throw new ConfigurationException("Configuration file not found: $path");
        }

        $config = require $path;
        
        if (!is_array($config)) {
            throw new ConfigurationException("Invalid configuration format: $path");
        }

        return $config;
    }

    private function validateCriticalConfig(string $name, array $config): void
    {
        // Load schema
        $schema = $this->loadSchema($name);
        
        // Validate against schema
        if (!$this->validateConfig($config, $schema)) {
            throw new ConfigurationException("Critical configuration '$name' validation failed");
        }

        // Additional security checks for critical configs
        if ($name === 'security') {
            $this->validateSecurityConfig($config);
        }
    }

    private function validateConfigValue($value, array $rules): bool
    {
        // Required check
        if ($rules['required'] ?? false) {
            if ($value === null) {
                return false;
            }
        }

        // Type check
        if (isset($rules['type'])) {
            if (!$this->checkValueType($value, $rules['type'])) {
                return false;
            }
        }

        // Range check
        if (isset($rules['range'])) {
            if (!$this->checkValueRange($value, $rules['range'])) {
                return false;
            }
        }

        // Pattern check
        if (isset($rules['pattern'])) {
            if (!preg_match($rules['pattern'], $value)) {
                return false;
            }
        }

        // Custom validation
        if (isset($rules['validator'])) {
            if (!$rules['validator']($value)) {
                return false;
            }
        }

        return true;
    }

    private function validateConfigChanges(string $name, array $current, array $changes): bool
    {
        // Load schema
        $schema = $this->loadSchema($name);
        
        // Validate changes against schema
        foreach ($changes as $key => $value) {
            if (!isset($schema[$key])) {
                return false;
            }
            
            if (!$this->validateConfigValue($value, $schema[$key])) {
                return false;
            }
        }

        // Validate resulting configuration
        $result = array_merge($current, $changes);
        return $this->validateConfig($result, $schema);
    }

    private function storeConfigBackup(array $backup): void
    {
        $path = storage_path("backups/config/{$backup['id']}.php");
        
        if (!file_put_contents($path, '<?php return ' . var_export($backup, true) . ';')) {
            throw new ConfigurationException('Failed to store configuration backup');
        }
    }

    private function generateConfigChecksum(array $config): string
    {
        return hash('sha256', serialize($config));
    }

    private function getConfigPath(string $name): string
    {
        return config_path("$name.php");
    }

    private function processEnvironmentVariables(array $config): array
    {
        array_walk_recursive($config, function(&$value) {
            if (is_string($value) && strpos($value, 'env:') === 0) {
                $envKey = substr($value, 4);
                $value = env($envKey);
            }
        });

        return $config;
    }

    private function validateSecurityConfig(array $config): void
    {
        $requiredSettings = [
            'encryption_key',
            'auth_timeout',
            'session_lifetime',
            'password_policy'
        ];

        foreach ($requiredSettings as $setting) {
            if (!isset($config[$setting])) {
                throw new ConfigurationException("Missing required security setting: $setting");
            }
        }
    }
}
