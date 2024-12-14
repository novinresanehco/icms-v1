<?php

namespace App\Core\UI;

class ComponentRegistry
{
    private array $components = [];
    private SecurityManagerInterface $security;

    public function __construct(SecurityManagerInterface $security)
    {
        $this->security = $security;
    }

    public function register(string $name, array $config): void
    {
        $this->validateComponentConfig($config);
        $this->components[$name] = $config;
    }

    public function resolve(string $name): array
    {
        if (!isset($this->components[$name])) {
            throw new ComponentException("Component not found: {$name}");
        }
        return $this->components[$name];
    }

    private function validateComponentConfig(array $config): void
    {
        $required = ['template', 'props', 'security'];
        foreach ($required as $field) {
            if (!isset($config[$field])) {
                throw new ComponentException("Missing required config: {$field}");
            }
        }
        
        if (!$this->security->validateComponentConfig($config)) {
            throw new SecurityException("Component config failed security validation");
        }
    }
}
