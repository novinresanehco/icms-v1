// File: app/Core/Plugin/Manager/PluginManager.php
<?php

namespace App\Core\Plugin\Manager;

class PluginManager
{
    protected PluginRepository $repository;
    protected PluginLoader $loader;
    protected PluginValidator $validator;
    protected EventDispatcher $events;
    protected array $loadedPlugins = [];

    public function register(Plugin $plugin): void
    {
        $this->validator->validate($plugin);
        
        DB::beginTransaction();
        try {
            $this->repository->save($plugin);
            $this->loader->load($plugin);
            $this->loadedPlugins[$plugin->getId()] = $plugin;
            
            $this->events->dispatch(new PluginRegistered($plugin));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Failed to register plugin: " . $e->getMessage());
        }
    }

    public function activate(string $pluginId): void
    {
        $plugin = $this->repository->find($pluginId);
        
        if (!$plugin) {
            throw new PluginException("Plugin not found: {$pluginId}");
        }

        if (!$this->validator->canActivate($plugin)) {
            throw new PluginException("Cannot activate plugin: dependencies not met");
        }

        DB::beginTransaction();
        try {
            $plugin->activate();
            $this->repository->save($plugin);
            $this->events->dispatch(new PluginActivated($plugin));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Failed to activate plugin: " . $e->getMessage());
        }
    }

    public function deactivate(string $pluginId): void
    {
        $plugin = $this->repository->find($pluginId);
        
        DB::beginTransaction();
        try {
            $plugin->deactivate();
            $this->repository->save($plugin);
            $this->events->dispatch(new PluginDeactivated($plugin));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PluginException("Failed to deactivate plugin: " . $e->getMessage());
        }
    }
}

// File: app/Core/Plugin/Loader/PluginLoader.php
<?php

namespace App\Core\Plugin\Loader;

class PluginLoader
{
    protected ServiceContainer $container;
    protected ConfigLoader $configLoader;
    protected RouteLoader $routeLoader;
    protected MigrationRunner $migrationRunner;

    public function load(Plugin $plugin): void
    {
        // Load plugin configuration
        $config = $this->configLoader->load($plugin);
        
        // Register plugin services
        foreach ($config->getServices() as $service) {
            $this->container->register($service);
        }

        // Load plugin routes
        if ($plugin->hasRoutes()) {
            $this->routeLoader->load($plugin);
        }

        // Run migrations if needed
        if ($plugin->hasMigrations() && !$plugin->isMigrated()) {
            $this->migrationRunner->run($plugin);
        }
    }

    public function unload(Plugin $plugin): void
    {
        // Unregister services
        foreach ($plugin->getServices() as $service) {
            $this->container->unregister($service);
        }

        // Remove routes
        if ($plugin->hasRoutes()) {
            $this->routeLoader->unload($plugin);
        }
    }
}

// File: app/Core/Plugin/Hook/HookManager.php
<?php

namespace App\Core\Plugin\Hook;

class HookManager
{
    protected array $hooks = [];
    protected HookValidator $validator;
    protected EventDispatcher $events;

    public function registerHook(string $name, callable $callback, int $priority = 10): void
    {
        if (!isset($this->hooks[$name])) {
            $this->hooks[$name] = [];
        }

        $this->hooks[$name][] = [
            'callback' => $callback,
            'priority' => $priority
        ];

        usort($this->hooks[$name], function($a, $b) {
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
            if ($result === false) {
                break;
            }
        }

        return $result;
    }

    public function hasHook(string $name): bool
    {
        return isset($this->hooks[$name]) && !empty($this->hooks[$name]);
    }
}

// File: app/Core/Plugin/Dependency/DependencyResolver.php
<?php

namespace App\Core\Plugin\Dependency;

class DependencyResolver
{
    protected PluginRepository $repository;
    protected VersionValidator $versionValidator;

    public function resolve(Plugin $plugin): array
    {
        $dependencies = $plugin->getDependencies();
        $resolved = [];
        $missing = [];

        foreach ($dependencies as $dependency) {
            $dependentPlugin = $this->repository->findByName($dependency['name']);
            
            if (!$dependentPlugin) {
                $missing[] = $dependency;
                continue;
            }

            if (!$this->versionValidator->satisfies(
                $dependentPlugin->getVersion(),
                $dependency['version']
            )) {
                throw new DependencyException(
                    "Version mismatch for {$dependency['name']}"
                );
            }

            $resolved[] = $dependentPlugin;
        }

        if (!empty($missing)) {
            throw new DependencyException(
                "Missing dependencies: " . implode(', ', array_column($missing, 'name'))
            );
        }

        return $resolved;
    }

    public function getLoadOrder(array $plugins): array
    {
        $graph = new DependencyGraph();

        foreach ($plugins as $plugin) {
            $graph->addNode($plugin);
            foreach ($plugin->getDependencies() as $dependency) {
                $graph->addEdge($plugin, $dependency);
            }
        }

        return $graph->getTopologicalSort();
    }
}
