<?php

namespace App\Core\Plugin\Contracts;

interface PluginInterface
{
    public function register(): void;
    public function boot(): void;
    public function install(): void;
    public function uninstall(): void;
    public function enable(): void;
    public function disable(): void;
    public function getId(): string;
    public function getName(): string;
    public function getVersion(): string;
    public function getDependencies(): array;
}

interface PluginRepositoryInterface
{
    public function all(): Collection;
    public function find(string $id): ?Plugin;
    public function findByName(string $name): ?Plugin;
    public function getEnabled(): Collection;
    public function isEnabled(string $id): bool;
    public function save(Plugin $plugin): void;
    public function delete(string $id): void;
}

namespace App\Core\Plugin\Models;

use Illuminate\Database\Eloquent\Model;

class Plugin extends Model
{
    protected $fillable = [
        'name',
        'identifier',
        'version',
        'description',
        'author',
        'is_enabled',
        'is_installed',
        'dependencies',
        'settings',
        'metadata'
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_installed' => 'boolean',
        'dependencies' => 'array',
        'settings' => 'array',
        'metadata' => 'array',
        'installed_at' => 'datetime',
        'last_enabled_at' => 'datetime'
    ];

    public function hooks(): HasMany
    {
        return $this->hasMany(PluginHook::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(PluginAsset::class);
    }
}

namespace App\Core\Plugin\Services;

use App\Core\Plugin\Contracts\PluginRepositoryInterface;
use App\Core\Plugin\Events\PluginInstalled;
use App\Core\Plugin\Events\PluginUninstalled;
use App\Core\Plugin\Events\PluginEnabled;
use App\Core\Plugin\Events\PluginDisabled;
use App\Core\Plugin\Exceptions\PluginException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PluginManager
{
    protected PluginRepositoryInterface $repository;
    protected DependencyResolver $dependencyResolver;
    protected PluginLoader $loader;

    public function __construct(
        PluginRepositoryInterface $repository,
        DependencyResolver $dependencyResolver,
        PluginLoader $loader
    ) {
        $this->repository = $repository;
        $this->dependencyResolver = $dependencyResolver;
        $this->loader = $loader;
    }

    public function install(string $pluginId): void
    {
        DB::beginTransaction();
        try {
            $plugin = $this->loader->load($pluginId);
            
            // Check dependencies
            $this->checkDependencies($plugin);
            
            // Run installation
            $plugin->install();
            
            // Save plugin data
            $this->repository->save($plugin);
            
            event(new PluginInstalled($plugin));
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Failed to install plugin: {$e->getMessage()}");
        }
    }

    public function uninstall(string $pluginId): void
    {
        DB::beginTransaction();
        try {
            $plugin = $this->repository->find($pluginId);
            
            if (!$plugin) {
                throw new PluginException("Plugin not found: {$pluginId}");
            }
            
            // Check if other plugins depend on this one
            $this->checkDependents($plugin);
            
            // Run uninstallation
            $plugin->uninstall();
            
            // Remove plugin data
            $this->repository->delete($pluginId);
            
            event(new PluginUninstalled($plugin));
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Failed to uninstall plugin: {$e->getMessage()}");
        }
    }

    public function enable(string $pluginId): void
    {
        DB::beginTransaction();
        try {
            $plugin = $this->repository->find($pluginId);
            
            if (!$plugin) {
                throw new PluginException("Plugin not found: {$pluginId}");
            }
            
            // Check dependencies
            $this->checkDependencies($plugin);
            
            // Enable plugin
            $plugin->enable();
            
            event(new PluginEnabled($plugin));
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Failed to enable plugin: {$e->getMessage()}");
        }
    }

    public function disable(string $pluginId): void
    {
        DB::beginTransaction();
        try {
            $plugin = $this->repository->find($pluginId);
            
            if (!$plugin) {
                throw new PluginException("Plugin not found: {$pluginId}");
            }
            
            // Check if other enabled plugins depend on this one
            $this->checkDependents($plugin, true);
            
            // Disable plugin
            $plugin->disable();
            
            event(new PluginDisabled($plugin));
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Failed to disable plugin: {$e->getMessage()}");
        }
    }

    protected function checkDependencies(PluginInterface $plugin): void
    {
        $missingDependencies = $this->dependencyResolver->checkDependencies($plugin);
        
        if (!empty($missingDependencies)) {
            throw new PluginException(
                "Missing dependencies: " . implode(', ', $missingDependencies)
            );
        }
    }

    protected function checkDependents(PluginInterface $plugin, bool $onlyEnabled = false): void
    {
        $dependents = $this->dependencyResolver->findDependents($plugin, $onlyEnabled);
        
        if (!empty($dependents)) {
            throw new PluginException(
                "Plugin is required by: " . implode(', ', $dependents)
            );
        }
    }
}

namespace App\Core\Plugin\Services;

class DependencyResolver
{
    protected PluginRepositoryInterface $repository;

    public function __construct(PluginRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function checkDependencies(PluginInterface $plugin): array
    {
        $missingDependencies = [];
        
        foreach ($plugin->getDependencies() as $dependency => $version) {
            $dependencyPlugin = $this->repository->findByName($dependency);
            
            if (!$dependencyPlugin || !$this->isVersionCompatible($dependencyPlugin->getVersion(), $version)) {
                $missingDependencies[] = "{$dependency} ({$version})";
            }
        }
        
        return $missingDependencies;
    }

    public function findDependents(PluginInterface $plugin, bool $onlyEnabled = false): array
    {
        $dependents = [];
        $plugins = $onlyEnabled ? $this->repository->getEnabled() : $this->repository->all();
        
        foreach ($plugins as $otherPlugin) {
            if ($this->dependsOn($otherPlugin, $plugin)) {
                $dependents[] = $otherPlugin->getName();
            }
        }
        
        return $dependents;
    }

    protected function isVersionCompatible(string $actual, string $required): bool
    {
        return version_compare($actual, $required, '>=');
    }

    protected function dependsOn(PluginInterface $plugin, PluginInterface $dependency): bool
    {
        return isset($plugin->getDependencies()[$dependency->getName()]);
    }
}

namespace App\Core\Plugin\Services;

class PluginHookManager
{
    protected array $hooks = [];

    public function registerHook(string $name, callable $callback, int $priority = 10): void
    {
        if (!isset($this->hooks[$name])) {
            $this->hooks[$name] = [];
        }

        $this->hooks[$name][] = [
            'callback' => $callback,
            'priority' => $priority
        ];

        // Sort hooks by priority
        usort($this->hooks[$name], function ($a, $b) {
            return $b['priority'] - $a['priority'];
        });
    }

    public function executeHook(string $name, array $args = []): mixed
    {
        if (!isset($this->hooks[$name])) {
            return null;
        }

        $result = null;
        foreach ($this->hooks[$name] as $hook) {
            $result = call_user_func_array($hook['callback'], $args);
        }

        return $result;
    }

    public function hasHook(string $name): bool
    {
        return isset($this->hooks[$name]) && !empty($this->hooks[$name]);
    }

    public function removeHook(string $name): void
    {
        unset($this->hooks[$name]);
    }
}

namespace App\Core\Plugin\Services;

class PluginAssetManager
{
    protected array $assets = [];
    protected string $publicPath;

    public function __construct(string $publicPath)
    {
        $this->publicPath = $publicPath;
    }

    public function registerAsset(string $type, string $path, array $attributes = [], int $priority = 10): void
    {
        if (!isset($this->assets[$type])) {
            $this->assets[$type] = [];
        }

        $this->assets[$type][] = [
            'path' => $path,
            'attributes' => $attributes,
            'priority' => $priority
        ];

        usort($this->assets[$type], function ($a, $b) {
            return $b['priority'] - $a['priority'];
        });
    }

    public function getAssets(string $type): array
    {
        return $this->assets[$type] ?? [];
    }

    public function publishAsset(PluginInterface $plugin, string $source, string $destination): void
    {
        $sourcePath = $plugin->getPath() . '/' . $source;
        $destinationPath = $this->publicPath . '/' . $destination;

        if (!is_dir(dirname($destinationPath))) {
            mkdir(dirname($destinationPath), 0755, true);
        }

        copy($sourcePath, $destinationPath);
    }
}
