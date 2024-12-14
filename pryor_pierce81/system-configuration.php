<?php

namespace App\Core\Config;

use Illuminate\Support\Facades\{Config, Cache};
use App\Core\Interfaces\{
    ConfigurationInterface,
    ValidationInterface,
    SecurityInterface
};

class SystemConfiguration implements ConfigurationInterface
{
    private ValidationInterface $validator;
    private SecurityInterface $security;
    private ConfigStore $store;
    private array $criticalConfigs;

    public function __construct(
        ValidationInterface $validator,
        SecurityInterface $security,
        ConfigStore $store
    ) {
        $this->validator = $validator;
        $this->security = $security;
        $this->store = $store;
        $this->criticalConfigs = [
            'security',
            'database',
            'cache',
            'monitoring'
        ];
    }

    public function initialize(): void
    {
        // Validate core configurations
        $this->validateConfigurations();
        
        // Load critical configurations
        $this->loadCriticalConfigs();
        
        // Set up security configurations
        $this->initializeSecurity();
        
        // Initialize monitoring
        $this->initializeMonitoring();
    }

    private function validateConfigurations(): void
    {
        foreach ($this->criticalConfigs as $config) {
            if (!$this->validator->validateConfig($config)) {
                throw new ConfigurationException("Invalid configuration: $config");
            }
        }
    }

    private function loadCriticalConfigs(): void
    {
        foreach ($this->criticalConfigs as $config) {
            $this->loadConfig($config);
        }
    }

    private function loadConfig(string $name): void
    {
        $config = $this->store->get($name);
        
        if (!$config) {
            throw new ConfigurationException("Missing configuration: $name");
        }

        Config::set($name, $config);
    }

    private function initializeSecurity(): void
    {
        $this->security->initialize($this->getSecurityConfig());
    }

    private function initializeMonitoring(): void
    {
        $this->monitor->initialize($this->getMonitoringConfig());
    }
}

class ConfigStore
{
    private array $configs = [];
    private EncryptionService $encryption;

    public function get(string $name): ?array
    {
        if (isset($this->configs[$name])) {
            return $this->decrypt($this->configs[$name]);
        }

        return null;
    }

    public function set(string $name, array $config): void
    {
        $this->configs[$name] = $this->encrypt($config);
    }

    private function encrypt(array $data): string
    {
        return $this->encryption->encrypt(serialize($data));
    }

    private function decrypt(string $encrypted): array
    {
        return unserialize($this->encryption->decrypt($encrypted));
    }
}

class SecurityConfiguration
{
    public function getDefaultConfig(): array
    {
        return [
            'encryption' => [
                'algorithm' => 'AES-256-GCM',
                'key_rotation' => 24, // hours
            ],
            'authentication' => [
                'multi_factor' => true,
                'session_timeout' => 15, // minutes
                'max_attempts' => 3
            ],
            'authorization' => [
                'strict_mode' => true,
                'role_hierarchy' => true
            ],
            'protection' => [
                'rate_limit' => 100, // requests per minute
                'ip_whitelist' => true,
                'force_ssl' => true
            ]
        ];
    }
}

class DatabaseConfiguration
{
    public function getDefaultConfig(): array
    {
        return [
            'connections' => [
                'write' => [
                    'host' => env('DB_HOST'),
                    'port' => env('DB_PORT', 3306),
                    'strict' => true,
                    'engine' => 'InnoDB'
                ],
                'read' => [
                    'host' => env('DB_READ_HOST'),
                    'port' => env('DB_READ_PORT', 3306)
                ]
            ],
            'pool' => [
                'min' => 5,
                'max' => 20
            ],
            'timeout' => 5,
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA')
            ]
        ];
    }
}

class CacheConfiguration
{
    public function getDefaultConfig(): array
    {
        return [
            'default' => 'redis',
            'stores' => [
                'redis' => [
                    'driver' => 'redis',
                    'connection' => 'cache',
                    'lock_connection' => 'default'
                ]
            ],
            'prefix' => env('CACHE_PREFIX', 'icms_cache'),
            'ttl' => 3600,
            'lock_timeout' => 30
        ];
    }
}

class MonitoringConfiguration
{
    public function getDefaultConfig(): array
    {
        return [
            'metrics' => [
                'enabled' => true,
                'interval' => 60,
                'retention' => 30 // days
            ],
            'alerts' => [
                'channels' => ['slack', 'email'],
                'thresholds' => [
                    'error_rate' => 0.01,
                    'response_time' => 500,
                    'memory_usage' => 80
                ]
            ],
            'logging' => [
                'level' => 'debug',
                'max_files' => 30,
                'channels' => ['daily', 'slack']
            ]
        ];
    }
}
