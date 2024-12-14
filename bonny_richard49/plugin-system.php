<?php

namespace App\Core\Plugin;

use App\Core\Security\CoreSecurityService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;

class PluginManager implements PluginInterface
{
    private CoreSecurityService $security;
    private PluginRegistry $registry;
    private PluginLoader $loader;
    private PluginValidator $validator;
    private DependencyResolver $resolver;
    private ResourceMonitor $monitor;

    public function __construct(
        CoreSecurityService $security,
        PluginRegistry $registry,
        PluginLoader $loader,
        PluginValidator $validator,
        DependencyResolver $resolver,
        ResourceMonitor $monitor
    ) {
        $this->security = $security;
        $this->registry = $registry;
        $this->loader = $loader;
        $this->validator = $validator;
        $this->resolver = $resolver;
        $this->monitor = $monitor;
    }

    public function load(string $plugin, Context $context): bool
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->executeLoad($plugin),
            ['action' => 'plugin.load', 'plugin' => $plugin, 'context' => $context]
        );
    }

    public function enable(string $plugin, Context $context): bool
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->executeEnable($plugin),
            ['action' => 'plugin.enable', 'plugin' => $plugin, 'context' => $context]
        );
    }

    public function disable(string $plugin, Context $context): bool
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->executeDisable($plugin),
            ['action' => 'plugin.disable', 'plugin' => $plugin, 'context' => $context]
        );
    }

    private function executeLoad(string $plugin): bool
    {
        try {
            // Validate plugin before loading
            $this->validatePlugin($plugin);
            
            // Check dependencies
            $dependencies = $this->resolver->resolveDependencies($plugin);
            foreach ($dependencies as $dependency) {
                if (!$this->registry->isLoaded($dependency)) {
                    throw new PluginDependencyException("Missing dependency: $dependency");
                }
            }
            
            // Load plugin under resource monitoring
            $instance = $this->monitor->track(
                fn() => $this->loader->load($plugin)
            );
            
            // Register plugin
            $this->registry->register($plugin, $instance);
            
            return true;
            
        } catch (PluginException $e) {
            $this->handlePluginError($e, $plugin, 'load');
            return false;
        }
    }

    private function executeEnable(string $plugin): bool
    {
        try {
            if (!$this->registry->exists($plugin)) {
                throw new PluginNotFoundException("Plugin not found: $plugin");
            }

            $instance = $this->registry->get($plugin);
            
            // Validate state before enabling
            $this->validator->validateState($instance);
            
            // Enable with resource limits
            $this->monitor->withLimits(
                fn() => $instance->enable(),
                $this->getResourceLimits($plugin)
            );
            
            $this->registry->setEnabled($plugin, true);
            $this->clearPluginCache($plugin);
            
            return true;
            
        } catch (PluginException $e) {
            $this->handlePluginError($e, $plugin, 'enable');
            return false;
        }
    }

    private function executeDisable(string $plugin): bool
    {
        try {
            if (!$this->registry->exists($plugin)) {
                throw new PluginNotFoundException("Plugin not found: $plugin");
            }

            $instance = $this->registry->get($plugin);
            
            // Check for dependent plugins
            $dependents = $this->resolver->findDependents($plugin);
            if (!empty($dependents)) {
                throw new PluginDependencyException(
                    "Cannot disable plugin with active dependents: " . 
                    implode(', ', $dependents)
                );
            }
            
            // Disable plugin
            $instance->disable();
            $this->registry->setEnabled($plugin, false);
            $this->clearPluginCache($plugin);
            
            return true;
            
        } catch (PluginException $e) {
            $this->handlePluginError($e, $plugin, 'disable');
            return false;
        }
    }

    private function validatePlugin(string $plugin): void
    {
        // Validate plugin structure
        if (!$this->validator->validateStructure($plugin)) {
            throw new PluginValidationException("Invalid plugin structure: $plugin");
        }

        // Validate plugin manifest
        if (!$this->validator->validateManifest($plugin)) {
            throw new PluginValidationException("Invalid plugin manifest: $plugin");
        }

        // Validate plugin code
        if (!$this->validator->validateCode($plugin)) {
            throw new PluginValidationException("Plugin code validation failed: $plugin");
        }

        // Validate plugin permissions
        if (!$this->validator->validatePermissions($plugin)) {
            throw new PluginValidationException("Plugin permission validation failed: $plugin");
        }
    }

    private function getResourceLimits(string $plugin): array
    {
        $defaults = Config::get('plugins.resource_limits.default', [
            'memory' => 67108864, // 64MB
            'time' => 30, // 30 seconds
            'files' => 100
        ]);

        $specific = Config::get("plugins.resource_limits.$plugin", []);
        
        return array_merge($defaults, $specific);
    }

    private function clearPluginCache(string $plugin): void
    {
        Cache::tags(['plugins', $plugin])->flush();
    }

    private function handlePluginError(\Exception $e, string $plugin, string $operation): void
    {
        logger()->error("Plugin operation failed", [
            'plugin' => $plugin,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class PluginRegistry
{
    private array $plugins = [];
    private array $enabled = [];

    public function register(string $name, PluginInstance $instance): void
    {
        $this->plugins[$name] = $instance;
        $this->enabled[$name] = false;
    }

    public function exists(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    public function isLoaded(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    public function isEnabled(string $name): bool
    {
        return $this->enabled[$name] ?? false;
    }

    public function get(string $name): ?PluginInstance
    {
        return $this->plugins[$name] ?? null;
    }

    public function setEnabled(string $name, bool $enabled): void
    {
        $this->enabled[$name] = $enabled;
    }

    public function getAll(): array
    {
        return $this->plugins;
    }
}

class PluginLoader
{
    private string $pluginPath;

    public function __construct(string $pluginPath)
    {
        $this->pluginPath = $pluginPath;
    }

    public function load(string $plugin): PluginInstance
    {
        $path = $this->getPluginPath($plugin);
        
        if (!file_exists($path)) {
            throw new PluginNotFoundException("Plugin file not found: $path");
        }

        require_once $path;
        
        $className = $this->getPluginClassName($plugin);
        if (!class_exists($className)) {
            throw new PluginException("Plugin class not found: $className");
        }

        $instance = new $className();
        if (!$instance instanceof PluginInstance) {
            throw new PluginException("Invalid plugin class: $className");
        }

        return $instance;
    }

    private function getPluginPath(string $plugin): string
    {
        return $this->pluginPath . '/' . $plugin . '/Plugin.php';
    }

    private function getPluginClassName(string $plugin): string
    {
        return "Plugins\\$plugin\\Plugin";
    }
}

class PluginValidator
{
    private array $requiredFiles = [
        'Plugin.php',
        'manifest.json',
        'composer.json'
    ];

    public function validateStructure(string $plugin): bool
    {
        foreach ($this->requiredFiles as $file) {
            if (!file_exists($this->getPluginPath($plugin, $file))) {
                return false;
            }
        }
        return true;
    }

    public function validateManifest(string $plugin): bool
    {
        $manifest = $this->loadManifest($plugin);
        
        return isset($manifest['name']) &&
               isset($manifest['version']) &&
               isset($manifest['description']) &&
               isset($manifest['author']) &&
               isset($manifest['license']);
    }

    public function validateCode(string $plugin): bool
    {
        $path = $this->getPluginPath($plugin, 'Plugin.php');
        
        // Validate file contents for security
        $content = file_get_contents($path);
        
        // Check for dangerous functions
        $dangerous = ['eval', 'exec', 'system', 'shell_exec'];
        foreach ($dangerous as $func) {
            if (strpos($content, $func) !== false) {
                return false;
            }
        }
        
        return true;
    }

    public function validatePermissions(string $plugin): bool
    {
        $manifest = $this->loadManifest($plugin);
        
        if (!isset($manifest['permissions'])) {
            return true;
        }
        
        $allowedPermissions = Config::get('plugins.allowed_permissions', []);
        
        foreach ($manifest['permissions'] as $permission) {
            if (!in_array($permission, $allowedPermissions)) {
                return false;
            }
        }
        
        return true;
    }

    public function validateState(PluginInstance $instance): bool
    {
        return $instance->getState() === PluginState::READY;
    }

    private function getPluginPath(string $plugin, string $file): string
    {
        return config('plugins.path') . '/' . $plugin . '/' . $file;
    }

    private function loadManifest(string $plugin): array
    {
        $path = $this->getPluginPath($plugin, 'manifest.json');
        return json_decode(file_get_contents($path), true);
    }
}

class DependencyResolver
{
    private PluginRegistry $registry;

    public function __construct(PluginRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function resolveDependencies(string $plugin): array
    {
        $manifest = $this->loadManifest($plugin);
        return $manifest['dependencies'] ?? [];
    }

    public function findDependents(string $plugin): array
    {
        $dependents = [];
        
        foreach ($this->registry->getAll() as $name => $instance) {
            $dependencies = $this->resolveDependencies($name);
            if (in_array($plugin, $dependencies)) {
                $dependents[] = $name;
            }
        }
        
        return $dependents;
    }

    private function loadManifest(string $plugin): array
    {
        $path = config('plugins.path') . '/' . $plugin . '/manifest.json';
        return json_decode(file_get_contents($path), true);
    }
}

class ResourceMonitor
{
    public function track(callable $operation): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        try {
            return $operation();
        } finally {
            $duration = microtime(true) - $startTime;
            $memory = memory_get_usage(true) - $startMemory;
            
            $this->recordMetrics($duration, $memory);
        }
    }

    public function withLimits(callable $operation, array $limits): mixed
    {
        $this->setLimits($limits);
        
        try {
            return $this->track($operation);
        } finally {
            $this->resetLimits();
        }
    }

    private function setLimits(array $limits): void
    {
        if (isset($limits['memory'])) {
            ini_set('memory_limit', $limits['memory']);
        }
        
        if (isset($limits['time'])) {
            set_time_limit($limits['time']);
        }
    }

    private function resetLimits(): void
    {
        ini_set('memory_limit', Config::get('plugins.memory_limit'));
        set_time_limit(Config::get('plugins.time_limit'));
    }

    private function recordMetrics(float $duration, int $memory): void
    {
        logger()->info('Plugin operation metrics', [
            'duration' => $duration,
            'memory' => $memory
        ]);
    }
}

interface PluginInstance
{
    public function enable(): void;
    public function disable(): void;
    public function getState(): PluginState;
}

enum PluginState
{
    case READY;
    case ENABLED;
    case DISABLED;
    case ERROR;
}

class PluginException extends \Exception {}
class PluginNotFoundException extends PluginException {}
class PluginValidationException extends PluginException {}
class PluginDependencyException extends PluginException {}
