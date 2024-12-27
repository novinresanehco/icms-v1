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
            $this->config['