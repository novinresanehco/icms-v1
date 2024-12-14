<?php

namespace App\Services;

use App\Interfaces\{SecurityServiceInterface, PluginInterface};
use Illuminate\Support\Facades\{File, Config, Event, Cache};
use Illuminate\Contracts\Container\Container;

class PluginService
{
    private SecurityServiceInterface $security;
    private Container $container;
    private string $pluginPath;
    private array $loadedPlugins = [];
    
    public function __construct(
        SecurityServiceInterface $security,
        Container $container
    ) {
        $this->security = $security;
        $this->container = $container;
        $this->pluginPath = base_path('plugins');
    }

    public function loadPlugin(string $pluginId): PluginInterface
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeLoadPlugin($pluginId),
            ['action' => 'plugin.load', 'permission' => 'plugin.manage']
        );
    }

    private function executeLoadPlugin(string $pluginId): PluginInterface
    {
        if (isset($this->loadedPlugins[$pluginId])) {
            return $this->loadedPlugins[$pluginId];
        }

        $plugin = $this->validateAndCreatePlugin($pluginId);
        $this->validateDependencies($plugin);
        
        $this->registerPlugin($plugin);
        $this->loadedPlugins[$pluginId] = $plugin;
        
        return $plugin;
    }

    public function activatePlugin(string $pluginId): void
    {
        $this->security->validateSecureOperation(
            fn() => $this->executeActivatePlugin($pluginId),
            ['action' => 'plugin.activate', 'permission' => 'plugin.manage']
        );
    }

    private function executeActivatePlugin(string $pluginId): void
    {
        $plugin = $this->loadPlugin($pluginId);
        
        if ($plugin->isActive()) {
            throw new PluginException('Plugin is already active');
        }

        try {
            $plugin->activate();
            $this->updatePluginStatus($pluginId, 'active');
            Event::dispatch('plugin.activated', $plugin);
        } catch (\Throwable $e) {
            throw new PluginException(
                "Failed to activate plugin: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function deactivatePlugin(string $pluginId): void
    {
        $this->security->validateSecureOperation(
            fn() => $this->executeDeactivatePlugin($pluginId),
            ['action' => 'plugin.deactivate', 'permission' => 'plugin.manage']
        );
    }

    private function executeDeactivatePlugin(string $pluginId): void
    {
        $plugin = $this->loadPlugin($pluginId);
        
        if (!$plugin->isActive()) {
            throw new PluginException('Plugin is not active');
        }

        try {
            $plugin->deactivate();
            $this->updatePluginStatus($pluginId, 'inactive');
            Event::dispatch('plugin.deactivated', $plugin);
        } catch (\Throwable $e) {
            throw new PluginException(
                "Failed to deactivate plugin: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function getActivePlugins(): array
    {
        return Cache::remember('active.plugins', 3600, function() {
            return array_filter($this->loadedPlugins, function($plugin) {
                return $plugin->isActive();
            });
        });
    }

    private function validateAndCreatePlugin(string $pluginId): PluginInterface
    {
        $pluginPath = $this->getPluginPath($pluginId);
        
        if (!File::isDirectory($pluginPath)) {
            throw new PluginException('Plugin directory not found');
        }

        $config = $this->loadPluginConfig($pluginId);
        $className = $config['main_class'];

        if (!class_exists($className)) {
            throw new PluginException('Plugin main class not found');
        }

        if (!in_array(PluginInterface::class, class_implements($className))) {
            throw new PluginException('Invalid plugin implementation');
        }

        return new $className($this->container);
    }

    private function validateDependencies(PluginInterface $plugin): void
    {
        foreach ($plugin->getDependencies() as $dependency => $version) {
            if (!$this->isDependencyMet($dependency, $version)) {
                throw new PluginException(
                    "Unmet dependency: {$dependency} {$version}"
                );
            }
        }
    }

    private function registerPlugin(PluginInterface $plugin): void
    {
        try {
            // Register service providers
            foreach ($plugin->getProviders() as $provider) {
                $this->container->register($provider);
            }

            // Register event listeners
            foreach ($plugin->getListeners() as $event => $listener) {
                Event::listen($event, $listener);
            }

            // Register configuration
            $pluginConfig = $plugin->getConfiguration();
            Config::set("plugins.{$plugin->getId()}", $pluginConfig);

            // Register routes if exists
            $routesPath = $this->getPluginPath($plugin->getId()) . '/routes.php';
            if (File::exists($routesPath)) {
                require $routesPath;
            }
            
        } catch (\Throwable $e) {
            throw new PluginException(
                "Failed to register plugin: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function isDependencyMet(string $dependency, string $version): bool
    {
        if (strpos($dependency, 'plugin:') === 0) {
            $pluginId = substr($dependency, 7);
            return isset($this->loadedPlugins[$pluginId]) && 
                   version_compare(
                       $this->loadedPlugins[$pluginId]->getVersion(),
                       $version,
                       '>='
                   );
        }

        return true;
    }

    private function loadPluginConfig(string $pluginId): array
    {
        $configPath = $this->getPluginPath($pluginId) . '/config.php';
        
        if (!File::exists($configPath)) {
            throw new PluginException('Plugin configuration not found');
        }

        $config = require $configPath;
        
        if (!is_array($config)) {
            throw new PluginException('Invalid plugin configuration');
        }

        return $config;
    }

    private function updatePluginStatus(string $pluginId, string $status): void
    {
        $statusPath = storage_path('plugins/status.json');
        $statuses = File::exists($statusPath) ? 
            json_decode(File::get($statusPath), true) : [];
        
        $statuses[$pluginId] = $status;
        File::put($statusPath, json_encode($statuses));
        
        Cache::tags(['plugins'])->flush();
    }

    private function getPluginPath(string $pluginId): string
    {
        return $this->pluginPath . '/' . $pluginId;
    }
}
