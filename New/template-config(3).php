<?php

namespace App\Core\Template\Config;

class TemplateConfig
{
    private array $config;
    private array $defaults = [
        'cache_enabled' => true,
        'cache_lifetime' => 3600,
        'compile_check' => true,
        'debug' => false,
        'strict_variables' => false,
        'auto_reload' => true,
        'throw_on_error' => true
    ];

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->defaults, $config);
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    public function merge(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function toArray(): array
    {
        return $this->config;
    }
}

class DatabaseConfig
{
    private array $config;

    public function __construct(array $config)
    {
        $this->validateConfig($config);
        $this->config = $config;
    }

    private function validateConfig(array $config): void
    {
        $required = ['host', 'database', 'username', 'password'];
        
        foreach ($required as $field) {
            if (!isset($config[$field])) {
                throw new \InvalidArgumentException(
                    "Missing required database configuration: {$field}"
                );
            }
        }
    }

    public function getDsn(): string
    {
        return sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $this->config['host'],
            $this->config['database']
        );
    }

    public function getUsername(): string
    {
        return $this->config['username'];
    }

    public function getPassword(): string
    {
        return $this->config['password'];
    }

    public function getOptions(): array
    {
        return $this->config['options'] ?? [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false
        ];
    }
}

class SecurityConfig
{
    private array $config;
    private array $defaults = [
        'escape_html' => true,
        'allowed_tags' => [],
        'allowed_protocols' => ['http', 'https'],
        'allow_php_tags' => false,
        'max_includes' => 10,
        'trusted_hosts' => [],
        'csrf_enabled' => true,
        'csrf_parameter' => '_token',
        'csrf_token_id' => '_csrf_token',
        'csrf_token_length' => 32
    ];

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->defaults, $config);
    }

    public function isHtmlEscapingEnabled(): bool
    {
        return $this->config['escape_html'];
    }

    public function getAllowedTags(): array
    {
        return $this->config['allowed_tags'];
    }

    public function getAllowedProtocols(): array
    {
        return $this->config['allowed_protocols'];
    }

    public function isPhpTagsAllowed(): bool
    {
        return $this->config['allow_php_tags'];
    }

    public function getMaxIncludes(): int
    {
        return $this->config['max_includes'];
    }

    public function getTrustedHosts(): array
    {
        return $this->config['trusted_hosts'];
    }

    public function isCsrfEnabled(): bool
    {
        return $this->config['csrf_enabled'];
    }

    public function getCsrfParameter(): string
    {
        return $this->config['csrf_parameter'];
    }

    public function getCsrfTokenId(): string
    {
        return $this->config['csrf_token_id'];
    }

    public function getCsrfTokenLength(): int
    {
        return $this->config['csrf_token_length'];
    }
}

class CacheConfig
{
    private array $config;
    private array $defaults = [
        'driver' => 'file',
        'path' => '/tmp/cache',
        'prefix' => 'template_',
        'lifetime' => 3600,
        'file_extension' => '.cache',
        'gc_probability' => 1,
        'gc_divisor' => 100,
        'redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0
        ],
        'memcached' => [
            'host' => '127.0.0.1',
            'port' => 11211
        ]
    ];

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->defaults, $config);
    }

    public function getDriver(): string
    {
        return $this->config['driver'];
    }

    public function getPath(): string
    {
        return $this->config['path'];
    }

    public function getPrefix(): string
    {
        return $this->config['prefix'];
    }

    public function getLifetime(): int
    {
        return $this->config['lifetime'];
    }

    public function getFileExtension(): string
    {
        return $this->config['file_extension'];
    }

    public function getGcProbability(): int
    {
        return $this->config['gc_probability'];
    }

    public function getGcDivisor(): int
    {
        return $this->config['gc_divisor'];
    }

    public function getRedisConfig(): array
    {
        return $this->config['redis'];
    }

    public function getMemcachedConfig(): array
    {
        return $this->config['memcached'];
    }
}

class ConfigurationManager
{
    private array $configs = [];

    public function addConfig(string $name, $config): void
    {
        $this->configs[$name] = $config;
    }

    public function getConfig(string $name)
    {
        if (!isset($this->configs[$name])) {
            throw new \InvalidArgumentException(
                "Configuration not found: {$name}"
            );
        }

        return $this->configs[$name];
    }

    public function hasConfig(string $name): bool
    {
        return isset($this->configs[$name]);
    }

    public function removeConfig(string $name): void
    {
        unset($this->configs[$name]);
    }

    public function getConfigs(): array
    {
        return $this->configs;
    }
}