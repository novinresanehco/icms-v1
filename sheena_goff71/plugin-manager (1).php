<?php

namespace App\Core\Plugin;

use Illuminate\Support\Facades\{DB, Event, Cache};
use App\Core\Service\BaseService;
use App\Core\Events\PluginEvent;
use App\Core\Exceptions\{PluginException, SecurityException};
use App\Models\Plugin;

class PluginManager extends BaseService
{
    protected array $validationRules = [
        'install' => [
            'name' => 'required|string|max:255|unique:plugins,name',
            'version' => 'required|string|regex:/^\d+\.\d+\.\d+$/',
            'description' => 'required|string',
            'author' => 'required|string',
            'namespace' => 'required|string|regex:/^[A-Z][A-Za-z0-9\\\\]+$/',
            'requires' => 'array',
            'requires.*' => 'string|exists:plugins,name',
            'permissions' => 'array',
            'permissions.*' => 'string|regex:/^[a-z_]+(\.[a-z_]+)*$/'
        ]
    ];

    protected array $securityConfig = [
        'allowed_operations' => [
            'read' => true,
            'write' => false,
            'network' => false,
            'system' => false
        ],
        'forbidden_classes' => [
            'ReflectionClass',
            'PDO',
            'DirectoryIterator',
            'exec',
            'shell_exec',
            'system',
            'passthru'
        ],
        'scan_patterns' => [
            'backdoor' => '/\b(eval|assert|system|exec|passthru|shell_exec)\s*\(/',
            'injection' => '/\$_(GET|POST|REQUEST|COOKIE|SERVER|FILES|ENV)/',
            'inclusion' => '/(include|require)(_once)?\s*\(.*(variable|concat|interpolation)/',
            'reflection' => '/ReflectionClass|get_class|get_object_vars/',
            'filesystem' => '/\b(fopen|file_get_contents|file_put_contents|unlink|rmdir)\b/'
        ]
    ];

    public function install(array $data): Result
    {
        return $this->executeOperation('install', $data);
    }

    public function uninstall(int $id): Result
    {
        return $this->executeOperation('uninstall', ['id' => $id]);
    }

    public function enable(int $id): Result
    {
        return $this->executeOperation('enable', ['id' => $id]);
    }

    public function disable(int $id): Result
    {
        return $this->executeOperation('disable', ['id' => $id]);
    }

    protected function processOperation(string $operation, array $data, array $context): mixed
    {
        return match($operation) {
            'install' => $this->processInstall($data),
            'uninstall' => $this->processUninstall($data),
            'enable' => $this->processEnable($data),
            'disable' => $this->processDisable($data),
            default => throw new PluginException("Invalid operation: {$operation}")
        };
    }

    protected function processInstall(array $data): Plugin
    {
        // Validate plugin security
        $this->validatePluginSecurity($data['namespace']);

        // Check dependencies
        $this->validateDependencies($data['requires'] ?? []);

        // Validate permissions
        $this->validatePermissions($data['permissions'] ?? []);

        // Install plugin
        $plugin = $this->repository->create([
            'name' => $data['name'],
            'version' => $data['version'],
            'description' => $data['description'],
            'author' => $data['author'],
            'namespace' => $data['namespace'],
            'requires' => $data['requires'] ?? [],
            'permissions' => $data['permissions'] ?? [],
            'status' => 'disabled',
            'installed_at' => now()
        ]);

        // Run installation hooks
        $this->runInstallationHooks($plugin);

        // Fire events
        $this->events->dispatch(new PluginEvent('installed', $plugin));

        return $plugin;
    }

    protected function processUninstall(array $data): bool
    {
        $plugin = $this->repository->findOrFail($data['id']);

        // Verify no dependent plugins
        $this->verifyNoDependents($plugin);

        // Run uninstallation hooks
        $this->runUninstallationHooks($plugin);

        // Remove plugin
        $deleted = $this->repository->delete($plugin);

        // Fire events
        $this->events->dispatch(new PluginEvent('uninstalled', $plugin));

        return $deleted;
    }

    protected function processEnable(array $data): Plugin
    {
        $plugin = $this->repository->findOrFail($data['id']);

        // Verify dependencies enabled
        $this->verifyDependenciesEnabled($plugin);

        // Verify security
        $this->validatePluginSecurity($plugin->namespace);

        // Enable plugin
        $enabled = $this->repository->update($plugin, [
            'status' => 'enabled',
            'enabled_at' => now()
        ]);

        // Run activation hooks
        $this->runActivationHooks($enabled);

        // Fire events
        $this->events->dispatch(new PluginEvent('enabled', $enabled));

        return $enabled;
    }

    protected function processDisable(array $data): Plugin
    {
        $plugin = $this->repository->findOrFail($data['id']);

        // Verify no enabled dependents
        $this->verifyNoDependentsEnabled($plugin);

        // Run deactivation hooks
        $this->runDeactivationHooks($plugin);

        // Disable plugin
        $disabled = $this->repository->update($plugin, [
            'status' => 'disabled',
            'enabled_at' => null
        ]);

        // Fire events
        $this->events->dispatch(new PluginEvent('disabled', $disabled));

        return $disabled;
    }

    protected function validatePluginSecurity(string $namespace): void
    {
        $pluginPath = $this->getPluginPath($namespace);

        // Scan plugin files
        $files = $this->getPluginFiles($pluginPath);
        foreach ($files as $file) {
            $this->scanFile($file);
        }

        // Validate plugin class
        $this->validatePluginClass($namespace);
    }

    protected function scanFile(string $file): void
    {
        $content = file_get_contents($file);

        foreach ($this->securityConfig['scan_patterns'] as $type => $pattern) {
            if (preg_match($pattern, $content)) {
                throw new SecurityException("Plugin contains forbidden {$type} pattern");
            }
        }

        foreach ($this->securityConfig['forbidden_classes'] as $class) {
            if (strpos($content, $class) !== false) {
                throw new SecurityException("Plugin contains forbidden class: {$class}");
            }
        }
    }

    protected function validatePluginClass(string $namespace): void
    {
        $reflection = new \ReflectionClass($namespace);

        // Check inheritance
        if (!$reflection->implementsInterface(PluginInterface::class)) {
            throw new PluginException('Plugin class must implement PluginInterface');
        }

        // Check methods
        $requiredMethods = ['boot', 'register', 'uninstall'];
        foreach ($requiredMethods as $method) {
            if (!$reflection->hasMethod($method)) {
                throw new PluginException("Plugin class must implement {$method}() method");
            }
        }
    }

    protected function validateDependencies(array $dependencies): void
    {
        foreach ($dependencies as $dependency) {
            $plugin = $this->repository->findByName($dependency);
            
            if (!$plugin) {
                throw new PluginException("Required plugin not found: {$dependency}");
            }

            if ($plugin->status !== 'enabled') {
                throw new PluginException("Required plugin not enabled: {$dependency}");
            }
        }
    }

    protected function validatePermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (!$this->security->isValidPermission($permission)) {
                throw new SecurityException("Invalid permission format: {$permission}");
            }
        }
    }

    protected function verifyNoDependents(Plugin $plugin): void
    {
        $dependents = $this->repository->findDependents($plugin->name);
        
        if ($dependents->isNotEmpty()) {
            throw new PluginException(
                'Cannot uninstall plugin - required by: ' . 
                $dependents->pluck('name')->implode(', ')
            );
        }
    }

    protected function verifyNoDependentsEnabled(Plugin $plugin): void
    {
        $enabledDependents = $this->repository->findEnabledDependents($plugin->name);
        
        if ($enabledDependents->isNotEmpty()) {
            throw new PluginException(
                'Cannot disable plugin - required by enabled plugins: ' .
                $enabledDependents->pluck('name')->implode(', ')
            );
        }
    }

    protected function verifyDependenciesEnabled(Plugin $plugin): void
    {
        foreach ($plugin->requires as $dependency) {
            $dep = $this->repository->findByName($dependency);
            
            if (!$dep || $dep->status !== 'enabled') {
                throw new PluginException(
                    "Required plugin not enabled: {$dependency}"
                );
            }
        }
    }

    protected function runInstallationHooks(Plugin $plugin): void
    {
        $instance = $this->getPluginInstance($plugin);
        $instance->install();
    }

    protected function runUninstallationHooks(Plugin $plugin): void
    {
        $instance = $this->getPluginInstance($plugin);
        $instance->uninstall();
    }

    protected function runActivationHooks(Plugin $plugin): void
    {
        $instance = $this->getPluginInstance($plugin);
        $instance->boot();
        $instance->register();
    }

    protected function runDeactivationHooks(Plugin $plugin): void
    {
        $instance = $this->getPluginInstance($plugin);
        $instance->deactivate();
    }

    protected function getPluginInstance(Plugin $plugin): PluginInterface
    {
        $class = $plugin->namespace;
        return new $class();
    }

    protected function getPluginPath(string $namespace): string
    {
        return app_path('Plugins/' . str_replace('\\', '/', $namespace));
    }

    protected function getPluginFiles(string $path): array
    {
        return glob($path . '/*.php');
    }

    protected function getValidationRules(string $operation): array
    {
        return $this->validationRules[$operation] ?? [];
    }

    protected function getRequiredPermissions(string $operation): array
    {
        return ["plugins.{$operation}"];
    }

    protected function isValidResult(string $operation, $result): bool
    {
        return match($operation) {
            'install', 'enable', 'disable' => $result instanceof Plugin,
            'uninstall' => is_bool($result),
            default => false
        };
    }
}
