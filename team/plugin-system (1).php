<?php

namespace App\Core\Plugin;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Events\PluginEvent;
use App\Core\Exceptions\{PluginException, SecurityException};
use Illuminate\Support\Facades\{DB, File};

class PluginManager implements PluginManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private array $loadedPlugins = [];
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->config = array_merge([
            'plugins_path' => 'plugins',
            'cache_enabled' => true,
            'isolation_level' => 'strict',
            'max_memory' => 128 * 1024 * 1024, // 128MB
            'execution_timeout' => 30
        ], $config);
    }

    public function load(string $identifier): Plugin
    {
        return $this->security->executeCriticalOperation(
            function() use ($identifier) {
                // Check if already loaded
                if (isset($this->loadedPlugins[$identifier])) {
                    return $this->loadedPlugins[$identifier];
                }

                // Validate plugin
                $this->validatePlugin($identifier);

                // Load plugin configuration
                $config = $this->loadPluginConfig($identifier);

                // Verify dependencies
                $this->verifyDependencies($config['dependencies'] ?? []);

                // Create plugin instance
                $plugin = $this->createPluginInstance($identifier, $config);

                // Initialize plugin
                $this->initializePlugin($plugin);

                // Cache plugin instance
                $this->loadedPlugins[$identifier] = $plugin;

                event(new PluginEvent('loaded', $identifier));

                return $plugin;
            },
            ['operation' => 'load_plugin']
        );
    }

    public function install(string $package, array $options = []): Plugin
    {
        return $this->security->executeCriticalOperation(
            function() use ($package, $options) {
                DB::beginTransaction();
                try {
                    // Verify package
                    $this->verifyPackage($package);

                    // Extract package
                    $identifier = $this->extractPackage($package);

                    // Validate plugin structure
                    $this->validatePluginStructure($identifier);

                    // Install dependencies
                    $this->installDependencies($identifier);

                    // Run installation
                    $plugin = $this->runInstallation($identifier, $options);

                    // Register plugin
                    $this->registerPlugin($plugin);

                    event(new PluginEvent('installed', $identifier));

                    DB::commit();
                    return $plugin;

                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->cleanup($identifier ?? null);
                    throw new PluginException('Plugin installation failed: ' . $e->getMessage());
                }
            },
            ['operation' => 'install_plugin']
        );
    }

    public function uninstall(string $identifier): void
    {
        $this->security->executeCriticalOperation(
            function() use ($identifier) {
                DB::beginTransaction();
                try {
                    // Get plugin
                    $plugin = $this->loadedPlugins[$identifier] ?? $this->load($identifier);

                    // Run uninstallation
                    $this->runUninstallation($plugin);

                    // Remove plugin files
                    $this->removePluginFiles($identifier);

                    // Unregister plugin
                    $this->unregisterPlugin($identifier);

                    // Clear cache
                    $this->clearPluginCache($identifier);

                    event(new PluginEvent('uninstalled', $identifier));

                    DB::commit();

                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new PluginException('Plugin uninstallation failed: ' . $e->getMessage());
                }
            },
            ['operation' => 'uninstall_plugin']
        );
    }

    public function execute(string $identifier, string $method, array $parameters = []): mixed
    {
        return $this->security->executeCriticalOperation(
            function() use ($identifier, $method, $parameters) {
                // Get plugin
                $plugin = $this->loadedPlugins[$identifier] ?? $this->load($identifier);

                // Validate method
                if (!method_exists($plugin, $method)) {
                    throw new PluginException("Method {$method} not found in plugin {$identifier}");
                }

                // Set up isolation
                $this->setupIsolation($plugin);

                try {
                    // Execute method
                    $result = $this->executeInIsolation(
                        fn() => $plugin->$method(...$parameters)
                    );

                    return $result;

                } finally {
                    // Clean up isolation
                    $this->cleanupIsolation();
                }
            },
            ['operation' => 'execute_plugin_method']
        );
    }

    protected function validatePlugin(string $identifier): void
    {
        $pluginPath = $this->getPluginPath($identifier);

        if (!File::exists($pluginPath)) {
            throw new PluginException("Plugin {$identifier} not found");
        }

        if (!File::exists($pluginPath . '/plugin.php')) {
            throw new PluginException("Invalid plugin structure for {$identifier}");
        }

        $this->validatePluginSecurity($identifier);
    }

    protected function validatePluginSecurity(string $identifier): void
    {
        // Scan for security vulnerabilities
        $scanResult = $this->security->scanDirectory($this->getPluginPath($identifier));

        if (!$scanResult['safe']) {
            throw new SecurityException(
                "Security validation failed for plugin {$identifier}: " . 
                $scanResult['reason']
            );
        }
    }

    protected function loadPluginConfig(string $identifier): array
    {
        $configFile = $this->getPluginPath($identifier) . '/config.php';

        if (!File::exists($configFile)) {
            throw new PluginException("Plugin configuration not found for {$identifier}");
        }

        $config = require $configFile;

        if (!$this->validatePluginConfig($config)) {
            throw new PluginException("Invalid plugin configuration for {$identifier}");
        }

        return $config;
    }

    protected function verifyDependencies(array $dependencies): void
    {
        foreach ($dependencies as $dependency => $version) {
            if (!$this->isDependencyMet($dependency, $version)) {
                throw new PluginException("Dependency not met: {$dependency} ({$version})");
            }
        }
    }

    protected function createPluginInstance(string $identifier, array $config): Plugin
    {
        $class = $this->loadPluginClass($identifier);

        return new $class($this, $config);
    }

    protected function loadPluginClass(string $identifier): string
    {
        $file = $this->getPluginPath($identifier) . '/plugin.php';
        require_once $file;

        $class = "Plugin\\{$identifier}\\Plugin";

        if (!class_exists($class)) {
            throw new PluginException("Plugin class not found: {$class}");
        }

        return $class;
    }

    protected function setupIsolation(Plugin $plugin): void
    {
        if ($this->config['isolation_level'] === 'strict') {
            // Set memory limit
            ini_set('memory_limit', $this->config['max_memory']);

            // Set execution time limit
            set_time_limit($this->config['execution_timeout']);

            // Set up error handling
            set_error_handler([$this, 'handlePluginError']);
        }
    }

    protected function executeInIsolation(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            throw new PluginException('Plugin execution failed: ' . $e->getMessage());
        }
    }

    protected function cleanupIsolation(): void
    {
        if ($this->config['isolation_level'] === 'strict') {
            // Restore error handling
            restore_error_handler();

            // Clean up resources
            gc_collect_cycles();
        }
    }

    protected function getPluginPath(string $identifier): string
    {
        return base_path($this->config['plugins_path'] . '/' . $identifier);
    }

    protected function clearPluginCache(string $identifier): void
    {
        if ($this->config['cache_enabled']) {
            $this->cache->tags(['plugins'])->forget($identifier);
        }
    }
}
