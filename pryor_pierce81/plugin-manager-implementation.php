<?php

namespace App\Core\Plugin;

use App\Core\Plugin\Contracts\PluginInterface;
use App\Core\Plugin\Exceptions\PluginException;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Collection;

class PluginManager
{
    private Collection $plugins;
    private CacheManager $cache;
    
    public function __construct(CacheManager $cache)
    {
        $this->plugins = new Collection();
        $this->cache = $cache;
    }

    /**
     * Register a new plugin
     *
     * @param PluginInterface $plugin
     * @throws PluginException
     */
    public function register(PluginInterface $plugin): void
    {
        // Validate plugin
        $this->validatePlugin($plugin);

        // Check dependencies
        $this->checkDependencies($plugin);

        try {
            // Initialize plugin
            $plugin->initialize();

            // Register hooks
            $plugin->registerHooks();

            // Register services 
            $plugin->registerServices();

            // Add to collection
            $this->plugins->put($plugin->getName(), $plugin);

            // Clear plugin cache
            $this->cache->tags(['plugins'])->flush();

        } catch (\Exception $e) {
            throw new PluginException(
                "Failed to register plugin {$plugin->getName()}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Enable a plugin
     *
     * @param string $name
     * @throws PluginException
     */
    public function enable(string $name): void 
    {
        $plugin = $this->plugins->get($name);

        if (!$plugin) {
            throw new PluginException("Plugin {$name} not found");
        }

        try {
            $plugin->enable();
            $this->cache->tags(['plugins'])->flush();
        } catch (\Exception $e) {
            throw new PluginException(
                "Failed to enable plugin {$name}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Disable a plugin
     *
     * @param string $name
     * @throws PluginException 
     */
    public function disable(string $name): void
    {
        $plugin = $this->plugins->get($name);

        if (!$plugin) {
            throw new PluginException("Plugin {$name} not found");
        }

        try {
            $plugin->disable();
            $this->cache->tags(['plugins'])->flush();
        } catch (\Exception $e) {
            throw new PluginException(
                "Failed to disable plugin {$name}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Update a plugin
     * 
     * @param string $name
     * @param string $newVersion
     * @throws PluginException
     */
    public function update(string $name, string $newVersion): void
    {
        $plugin = $this->plugins->get($name);

        if (!$plugin) {
            throw new PluginException("Plugin {$name} not found");
        }

        try {
            $oldVersion = $plugin->getVersion();
            $plugin->update($oldVersion, $newVersion);
            $this->cache->tags(['plugins'])->flush();
        } catch (\Exception $e) {
            throw new PluginException(
                "Failed to update plugin {$name}: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get all registered plugins
     *
     * @return Collection
     */
    public function all(): Collection
    {
        return $this->plugins;
    }

    /**
     * Get enabled plugins
     *
     * @return Collection
     */
    public function enabled(): Collection
    {
        return $this->plugins->filter(function ($plugin) {
            return $plugin->isEnabled();
        });
    }

    /**
     * Get disabled plugins
     *
     * @return Collection
     */
    public function disabled(): Collection
    {
        return $this->plugins->reject(function ($plugin) {
            return $plugin->isEnabled();
        });
    }

    /**
     * Validate plugin requirements
     *
     * @param PluginInterface $plugin
     * @throws PluginException
     */
    private function validatePlugin(PluginInterface $plugin): void
    {
        if ($this->plugins->has($plugin->getName())) {
            throw new PluginException(
                "Plugin {$plugin->getName()} already registered"
            );
        }

        $requiredMethods = [
            'getName',
            'getVersion',
            'getDescription',
            'getDependencies',
            'initialize',
            'enable',
            'disable',
            'isEnabled',
            'getConfig',
            'registerHooks',
            'registerServices',
            'update'
        ];

        foreach ($requiredMethods as $method) {
            if (!method_exists($plugin, $method)) {
                throw new PluginException(
                    "Plugin {$plugin->getName()} missing required method: {$method}"
                );
            }
        }
    }

    /**
     * Check plugin dependencies
     *
     * @param PluginInterface $plugin
     * @throws PluginException
     */
    private function checkDependencies(PluginInterface $plugin): void
    {
        foreach ($plugin->getDependencies() as $dependency) {
            if (!$this->plugins->has($dependency)) {
                throw new PluginException(
                    "Plugin {$plugin->getName()} requires {$dependency} which is not installed"
                );
            }

            $requiredPlugin = $this->plugins->get($dependency);
            if (!$requiredPlugin->isEnabled()) {
                throw new PluginException(
                    "Plugin {$plugin->getName()} requires {$dependency} which is not enabled"
                );
            }
        }
    }
}
