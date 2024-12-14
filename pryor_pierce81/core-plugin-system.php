<?php

namespace App\Core\Plugin;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\{PluginException, SecurityException};
use Illuminate\Support\Facades\{DB, File};

class PluginManager implements PluginManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private DependencyManager $dependencies;
    private PluginRegistry $registry;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        DependencyManager $dependencies,
        PluginRegistry $registry,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->dependencies = $dependencies;
        $this->registry = $registry;
        $this->config = $config;
    }

    public function loadPlugin(string $identifier): Plugin
    {
        return $this->security->executeCriticalOperation(
            new LoadPluginOperation(
                $identifier,
                $this->validator,
                $this->dependencies,
                $this->registry
            ),
            SecurityContext::system()
        );
    }

    public function installPlugin(string $path): bool
    {
        return DB::transaction(function() use ($path) {
            $plugin = $this->validatePlugin($path);
            $this->checkDependencies($plugin);
            $this->installFiles($plugin);
            $this->registry->register($plugin);
            $this->cache->tags(['plugins'])->flush();
            return true;
        });
    }

    public function uninstallPlugin(string $identifier): bool
    {
        return DB::transaction(function() use ($identifier) {
            $plugin = $this->registry->get($identifier);
            $this->validateUninstall($plugin);
            $this->removeFiles($plugin);
            $this->registry->unregister($plugin);
            $this->cache->tags(['plugins'])->flush();
            return true;
        });
    }

    public function getActive(): array
    {
        return $this->cache->tags(['plugins'])->remember(
            'active_plugins',
            fn() => $this->registry->getActive()
        );
    }

    private function validatePlugin(string $path): Plugin
    {
        if (!File::exists($path)) {
            throw new PluginException("Plugin not found: {$path}");
        }

        $manifest = $this->loadManifest($path);
        $this->validateManifest($manifest);
        
        $plugin = new Plugin($manifest);
        
        if (!$this->validator->validatePluginCode($plugin)) {
            throw new SecurityException('Plugin code validation failed');
        }

        if (!$this->validator->validatePluginPermissions($plugin)) {
            throw new SecurityException('Plugin has invalid permissions');
        }

        return $plugin;
    }

    private function loadManifest(string $path): array
    {
        $manifestPath = $path . '/plugin.json';
        
        if (!File::exists($manifestPath)) {
            throw new PluginException('Plugin manifest not found');
        }

        $manifest = json_decode(File::get($manifestPath), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PluginException('Invalid plugin manifest');
        }

        return $manifest;
    }

    private function validateManifest(array $manifest): void
    {
        $required = ['name', 'version', 'entry_point', 'permissions'];
        
        foreach ($required as $field) {
            if (!isset($manifest[$field])) {
                throw new PluginException("Missing required field: {$field}");
            }
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $manifest['name'])) {
            throw new PluginException('Invalid plugin name format');
        }

        if (!preg_match('/^\d+\.\d+\.\d+$/', $manifest['version'])) {
            throw new PluginException('Invalid version format');
        }
    }

    private function checkDependencies(Plugin $plugin): void
    {
        $dependencies = $plugin->getDependencies();
        
        foreach ($dependencies as $dependency) {
            if (!$this->dependencies->checkDependency($dependency)) {
                throw new PluginException("Unmet dependency: {$dependency}");
            }
        }
    }

    private function validateUninstall(Plugin $plugin): void
    {
        $dependents = $this->dependencies->getDependents($plugin);
        
        if (!empty($dependents)) {
            throw new PluginException(
                "Cannot uninstall: plugin required by " . 
                implode(', ', array_keys($dependents))
            );
        }
    }

    private function installFiles(Plugin $plugin): void
    {
        $source = $plugin->getSourcePath();
        $target = $this->getPluginPath($plugin);

        try {
            File::copyDirectory($source, $target);
        } catch (\Exception $e) {
            throw new PluginException(
                "Failed to install plugin files: {$e->getMessage()}"
            );
        }
    }

    private function removeFiles(Plugin $plugin): void
    {
        $path = $this->getPluginPath($plugin);
        
        try {
            File::deleteDirectory($path);
        } catch (\Exception $e) {
            throw new PluginException(
                "Failed to remove plugin files: {$e->getMessage()}"
            );
        }
    }

    private function getPluginPath(Plugin $plugin): string
    {
        return $this->config['plugins_path'] . '/' . $plugin->getIdentifier();
    }
}

class Plugin
{
    private string $identifier;
    private string $name;
    private string $version;
    private string $entryPoint;
    private array $permissions;
    private array $dependencies;
    private ?PluginInstance $instance = null;

    public function __construct(array $manifest)
    {
        $this->identifier = $manifest['name'];
        $this->name = $manifest['name'];
        $this->version = $manifest['version'];
        $this->entryPoint = $manifest['entry_point'];
        $this->permissions = $manifest['permissions'];
        $this->dependencies = $manifest['dependencies'] ?? [];
    }

    public function getInstance(): PluginInstance
    {
        if ($this->instance === null) {
            require_once $this->getEntryPointPath();
            $class = $this->getPluginClass();
            $this->instance = new $class($this);
        }
        
        return $this->instance;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    private function getEntryPointPath(): string
    {
        return $this->getSourcePath() . '/' . $this->entryPoint;
    }

    private function getPluginClass(): string
    {
        return str_replace(
            ['/', '.php'],
            ['\\', ''],
            $this->entryPoint
        );
    }
}
