<?php

namespace App\Core\Modules;

interface ModuleInterface
{
    public function getName(): string;
    public function getVersion(): string;
    public function getDependencies(): array;
    public function initialize(): void;
    public function install(): void;
    public function uninstall(): void;
    public function isEnabled(): bool;
}

abstract class AbstractModule implements ModuleInterface
{
    protected string $name;
    protected string $version;
    protected array $dependencies = [];
    protected bool $enabled = false;

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

class ModuleRegistry
{
    protected array $modules = [];
    protected array $enabled = [];

    public function register(ModuleInterface $module): void
    {
        $name = $module->getName();
        
        if (isset($this->modules[$name])) {
            throw new \RuntimeException("Module {$name} is already registered");
        }

        $this->validateDependencies($module);
        $this->modules[$name] = $module;
    }

    public function enable(string $name): void
    {
        if (!isset($this->modules[$name])) {
            throw new \RuntimeException("Module {$name} is not registered");
        }

        $module = $this->modules[$name];
        $this->enableDependencies($module);
        
        try {
            $module->install();
            $this->enabled[$name] = true;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to enable module {$name}: " . $e->getMessage());
        }
    }

    public function disable(string $name): void
    {
        if (!isset($this->enabled[$name])) {
            return;
        }

        $module = $this->modules[$name];
        $this->validateDisable($name);
        
        try {
            $module->uninstall();
            unset($this->enabled[$name]);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to disable module {$name}: " . $e->getMessage());
        }
    }

    protected function validateDependencies(ModuleInterface $module): void
    {
        foreach ($module->getDependencies() as $dependency => $version) {
            if (!isset($this->modules[$dependency])) {
                throw new \RuntimeException("Missing dependency: {$dependency}");
            }

            if (!version_compare($this->modules[$dependency]->getVersion(), $version, '>=')) {
                throw new \RuntimeException("Invalid dependency version for {$dependency}");
            }
        }
    }

    protected function enableDependencies(ModuleInterface $module): void
    {
        foreach ($module->getDependencies() as $dependency => $version) {
            if (!isset($this->enabled[$dependency])) {
                $this->enable($dependency);
            }
        }
    }

    protected function validateDisable(string $name): void
    {
        foreach ($this->modules as $module) {
            if (!isset($this->enabled[$module->getName()])) {
                continue;
            }

            if (isset($module->getDependencies()[$name])) {
                throw new \RuntimeException("Cannot disable {$name}: required by " . $module->getName());
            }
        }
    }
}

class ModuleManager
{
    protected ModuleRegistry $registry;
    protected array $configurations = [];

    public function __construct(ModuleRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function loadModules(): void
    {
        foreach ($this->configurations as $config) {
            try {
                $module = $this->createModule($config);
                $this->registry->register($module);
                
                if ($config['enabled'] ?? false) {
                    $this->registry->enable($module->getName());
                }
            } catch (\Exception $e) {
                report($e);
            }
        }
    }

    protected function createModule(array $config): ModuleInterface
    {
        $class = $config['class'];
        
        if (!class_exists($class)) {
            throw new \RuntimeException("Module class {$class} not found");
        }

        if (!is_subclass_of($class, ModuleInterface::class)) {
            throw new \RuntimeException("Invalid module class: {$class}");
        }

        return new $class($config);
    }

    public function addConfiguration(array $config): void
    {
        $this->validateConfiguration($config);
        $this->configurations[] = $config;
    }

    protected function validateConfiguration(array $config): void
    {
        $required = ['name', 'class', 'version'];
        
        foreach ($required as $field) {
            if (!isset($config[$field])) {
                throw new \InvalidArgumentException("Missing required configuration field: {$field}");
            }
        }
    }
}
