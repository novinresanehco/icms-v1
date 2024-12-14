```php
namespace App\Core\Plugin\Integration;

class PluginIntegrator implements IntegratorInterface
{
    private PluginManager $plugins;
    private EventDispatcher $events;
    private CacheManager $cache;

    public function integrate(Plugin $plugin): void
    {
        $this->events->dispatch('plugin.integrating', $plugin);
        
        try {
            $this->registerHooks($plugin);
            $this->registerEvents($plugin);
            $this->cacheIntegration($plugin);
            
            $this->events->dispatch('plugin.integrated', $plugin);
        } catch (\Exception $e) {
            $this->events->dispatch('plugin.integration_failed', [$plugin, $e]);
            throw $e;
        }
    }

    private function registerHooks(Plugin $plugin): void
    {
        foreach ($plugin->getHooks() as $hook) {
            $this->plugins->registerHook(
                $hook['name'],
                [$plugin, $hook['method']]
            );
        }
    }

    private function cacheIntegration(Plugin $plugin): void
    {
        $this->cache->forever(
            "plugin.{$plugin->getId()}.integration",
            $plugin->getIntegrationData()
        );
    }
}
```
