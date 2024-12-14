```php
namespace App\Core\Plugin;

use App\Core\Plugin\Contracts\PluginInterface;
use App\Core\Plugin\Registry\PluginRegistry;
use App\Core\Plugin\Loader\PluginLoader;
use App\Core\Plugin\Validator\PluginValidator;
use App\Core\Exceptions\PluginException;

class PluginManager
{
    protected PluginRegistry $registry;
    protected PluginLoader $loader;
    protected PluginValidator $validator;
    protected array $activePlugins = [];

    /**
     * PluginManager constructor.
     */
    public function __construct(
        PluginRegistry $registry,
        PluginLoader $loader,
        PluginValidator $validator
    ) {
        $this->registry = $registry;
        $this->loader = $loader;
        $this->validator = $validator;
    }

    /**
     * Load and initialize a plugin
     */
    public function loadPlugin(string $name): PluginInterface
    {
        try {
            // Check if plugin is already loaded
            if (isset($this->activePlugins[$name])) {
                return $this->activePlugins[$name];
            }

            // Load plugin
            $plugin = $this->loader->load($name);

            // Validate plugin
            $this->validator->validate($plugin);

            // Check dependencies
            $this->checkDependencies($plugin);

            // Initialize plugin
            $plugin->initialize();

            // Register plugin
            $this->registry->register($plugin);
            $this->activePlugins[$name] = $plugin;

            return $plugin;
        } catch (\Exception $e) {
            throw new PluginException("Failed to load plugin '{$name}': {$e->getMessage()}");
        }
    }

    /**
     * Enable a plugin
     */
    public function enablePlugin(string $name): void
    {
        try {
            $plugin = $this->loadPlugin($name);
            $plugin->enable();
            $this->registry->updateStatus($name, 'enabled');
        } catch (\Exception $e) {
            throw new PluginException("Failed to enable plugin '{$name}': {$e->getMessage()}");
        }
    }

    /**
     * Disable a plugin
     */
    public function disablePlugin(string $name): void
    {
        try {
            if (isset($this->activePlugins[$name])) {
                $plugin = $this->activePlugins[$name];
                $plugin->disable();
                $this->registry->updateStatus($name, 'disabled');
                unset($this->activePlugins[$name]);
            }
        } catch (\Exception $e) {
            throw new PluginException("Failed to disable plugin '{$name}': {$e->getMessage()}");
        }
    }

    /**
     * Install a plugin
     */
    public function installPlugin(string $path): void
    {
        try {
            // Validate plugin package
            $this->validator->validatePackage($path);

            // Install plugin
            $this->loader->install($path);

            // Load plugin configuration
            $config = $this->loader->loadConfiguration($path);

            // Register plugin in registry
            $this->registry->add($config);
        } catch (\Exception $e) {
            throw new PluginException("Failed to install plugin: {$e->getMessage()}");
        }
    }

    /**
     * Uninstall a plugin
     */
    public function uninstallPlugin(string $name): void
    {
        try {
            // Disable plugin first
            $this->disablePlugin($name);

            // Remove plugin files
            $this->loader->uninstall($name);

            // Remove from registry
            $this->registry->remove($name);
        } catch (\Exception $e) {
            throw new PluginException("Failed to uninstall plugin '{$name}': {$e->getMessage()}");
        }
    }

    /**
     * Check plugin dependencies
     */
    protected function checkDependencies(PluginInterface $plugin): void
    {
        foreach ($plugin->getDependencies() as $dependency) {
            if (!$this->registry->exists($dependency)) {
                throw new PluginException("Missing dependency: {$dependency}");
            }

            if (!isset($this->activePlugins[$dependency])) {
                $this->loadPlugin($dependency);
            }
        }
    }

    /**
     * Get active plugins
     */
    public function getActivePlugins(): array
    {
        return $this->activePlugins;
    }

    /**
     * Get all plugins
     */
    public function getAllPlugins(): array
    {
        return $this->registry->getAll();
    }
}
```
