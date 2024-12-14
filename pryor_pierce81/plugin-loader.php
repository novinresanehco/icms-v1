```php
namespace App\Core\Plugin\Loader;

use App\Core\Plugin\Contracts\PluginInterface;
use App\Core\Plugin\Exceptions\PluginLoadException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Config;

class PluginLoader
{
    protected string $pluginPath;
    protected array $loadedPlugins = [];

    /**
     * PluginLoader constructor.
     */
    public function __construct()
    {
        $this->pluginPath = Config::get('plugins.path', base_path('plugins'));
    }

    /**
     * Load a plugin
     */
    public function load(string $name): PluginInterface
    {
        try {
            $pluginClass = $this->findPluginClass($name);
            
            if (!class_exists($pluginClass)) {
                require_once $this->getPluginFile($name);
            }

            if (!is_subclass_of($pluginClass, PluginInterface::class)) {
                throw new PluginLoadException("Invalid plugin class: {$pluginClass}");
            }

            return new $pluginClass();
        } catch (\Exception $e) {
            throw new PluginLoadException("Failed to load plugin '{$name}': {$e->getMessage()}");
        }
    }

    /**
     * Install a plugin
     */
    public function install(string $path): void
    {
        try {
            // Extract plugin if it's a package
            if ($this->isPackage($path)) {
                $path = $this->extractPackage($path);
            }

            // Copy plugin files
            File::copyDirectory($path, $this->getPluginPath(basename($path)));

            // Register autoloader
            $this->registerAutoloader(basename($path));
        } catch (\Exception $e) {
            throw new PluginLoadException("Failed to install plugin: {$e->getMessage()}");
        }
    }

    /**
     * Uninstall a plugin
     */
    public function uninstall(string $name): void
    {
        try {
            $pluginPath = $this->getPluginPath($name);
            
            if (File::exists($pluginPath)) {
                File::deleteDirectory($pluginPath);
            }
        } catch (\Exception $e) {
            throw new PluginLoadException("Failed to uninstall plugin '{$name}': {$e->getMessage()}");
        }
    }

    /**
     * Load plugin configuration
     */
    public function loadConfiguration(string $path): array
    {
        $configFile = $path . '/plugin.json';

        if (!File::exists($configFile)) {
            throw new PluginLoadException("Plugin configuration file not found");
        }

        $config = json_decode(File::get($configFile), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new PluginLoadException("Invalid plugin configuration file");
        }

        return $config;
    }

    /**
     * Find plugin class
     */
    protected function findPluginClass(string $name): string
    {
        $pluginFile = $this->getPluginFile($name);
        
        if (!File::exists($pluginFile)) {
            throw new PluginLoadException("Plugin file not found: {$pluginFile}");
        }

        $content = File::get($pluginFile);
        preg_match('/namespace\s+(.+?);/', $content, $matches);
        $namespace = $matches[1] ?? '';

        preg_match('/class\s+(\w+)/', $content, $matches);
        $class = $matches[1] ?? '';

        if (!$namespace || !$class) {
            throw new PluginLoadException("Could not determine plugin class");
        }

        return $namespace . '\\' . $class;
    }

    /**
     * Get plugin file path
     */
    protected function getPluginFile(string $name): string
    {
        return $this->getPluginPath($name) . '/Plugin.php';
    }

    /**
     * Get plugin path
     */
    protected function getPluginPath(string $name): string
    {
        return $this->pluginPath . '/' . $name;
    }

    /**
     * Register plugin autoloader
     */
    protected function registerAutoloader(string $name): void
    {
        $pluginPath = $this->getPluginPath($name);
        $composerFile = $pluginPath . '/composer.json';

        if (File::exists($composerFile)) {
            $composer = json_decode(File::get($composerFile), true);
            
            if (isset($composer['autoload']['psr-4'])) {
                foreach ($composer['autoload']['psr-4'] as $namespace => $path) {
                    $loader = require base_path('vendor/autoload.php');
                    $loader->addPsr4($namespace, $pluginPath . '/' . $path);
                }
            }
        }
    }

    /**
     * Check if path is a package
     */
    protected function isPackage(string $path): bool
    {
        return pathinfo($path, PATHINFO_EXTENSION) === 'zip';
    }

    /**
     * Extract plugin package
     */
    protected function extractPackage(string $path): string
    {
        $zip = new \ZipArchive();
        
        if ($zip->open($path) === true) {
            $extractPath = storage_path('app/tmp/plugins/' . uniqid());
            $zip->extractTo($extractPath);
            $zip->close();
            return $extractPath;
        }

        throw new PluginLoadException("Failed to extract plugin package");
    }
}
```
