namespace App\Core\Plugin;

class PluginManager implements PluginManagerInterface
{
    private SecurityManager $security;
    private ValidatorService $validator;
    private CacheManager $cache;
    private FileSystem $files;
    private EventDispatcher $events;
    private MetricsCollector $metrics;
    private array $plugins = [];

    public function __construct(
        SecurityManager $security,
        ValidatorService $validator,
        CacheManager $cache,
        FileSystem $files,
        EventDispatcher $events,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->files = $files;
        $this->events = $events;
        $this->metrics = $metrics;
    }

    public function load(string $plugin): bool
    {
        return $this->security->executeCriticalOperation(
            new LoadPluginOperation(
                $plugin,
                $this->validator,
                $this->files
            ),
            SecurityContext::fromRequest()
        );
    }

    public function register(PluginInterface $plugin): void
    {
        $this->security->executeCriticalOperation(
            new RegisterPluginOperation(
                $plugin,
                $this->validatePlugin($plugin),
                $this->cache
            ),
            SecurityContext::fromRequest()
        );

        $this->plugins[$plugin->getName()] = $plugin;
    }

    public function boot(): void
    {
        foreach ($this->getEnabledPlugins() as $plugin) {
            $this->bootPlugin($plugin);
        }
    }

    private function bootPlugin(PluginInterface $plugin): void
    {
        $startTime = microtime(true);

        try {
            $this->security->executeCriticalOperation(
                new BootPluginOperation($plugin, $this->events),
                SecurityContext::fromRequest()
            );

            $this->metrics->timing(
                "plugin.boot.{$plugin->getName()}",
                microtime(true) - $startTime
            );

        } catch (\Exception $e) {
            $this->handlePluginFailure($plugin, $e);
            throw $e;
        }
    }

    private function validatePlugin(PluginInterface $plugin): array
    {
        $manifest = $plugin->getManifest();
        
        $rules = [
            'name' => 'required|string|max:255',
            'version' => 'required|string',
            'dependencies' => 'array',
            'permissions' => 'array'
        ];

        return $this->validator->validate($manifest, $rules);
    }

    private function handlePluginFailure(PluginInterface $plugin, \Exception $e): void
    {
        $this->metrics->increment("plugin.failures.{$plugin->getName()}");

        $this->events->dispatch(
            new PluginFailureEvent($plugin, $e)
        );

        if ($plugin->isCritical()) {
            throw new CriticalPluginException(
                "Critical plugin {$plugin->getName()} failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function getEnabledPlugins(): array
    {
        return $this->cache->remember('enabled_plugins', 3600, function () {
            return array_filter($this->plugins, function ($plugin) {
                return $plugin->isEnabled();
            });
        });
    }

    public function hasPlugin(string $name): bool
    {
        return isset($this->plugins[$name]);
    }

    public function getPlugin(string $name): ?PluginInterface
    {
        return $this->plugins[$name] ?? null;
    }

    public function validateDependencies(PluginInterface $plugin): void
    {
        foreach ($plugin->getDependencies() as $dependency => $version) {
            if (!$this->hasPlugin($dependency)) {
                throw new MissingDependencyException(
                    "Missing dependency {$dependency} for plugin {$plugin->getName()}"
                );
            }

            if (!$this->validateVersion($this->getPlugin($dependency), $version)) {
                throw new InvalidDependencyException(
                    "Invalid version for dependency {$dependency} in plugin {$plugin->getName()}"
                );
            }
        }
    }

    private function validateVersion(PluginInterface $plugin, string $requirement): bool
    {
        return version_compare($plugin->getVersion(), $requirement, '>=');
    }

    public function uninstall(string $plugin): bool
    {
        return $this->security->executeCriticalOperation(
            new UninstallPluginOperation(
                $this->getPlugin($plugin),
                $this->files,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function enable(string $plugin): bool
    {
        $pluginInstance = $this->getPlugin($plugin);
        
        if (!$pluginInstance) {
            throw new PluginNotFoundException("Plugin {$plugin} not found");
        }

        return $this->security->executeCriticalOperation(
            new EnablePluginOperation($pluginInstance, $this->cache),
            SecurityContext::fromRequest()
        );
    }

    public function disable(string $plugin): bool
    {
        $pluginInstance = $this->getPlugin($plugin);
        
        if (!$pluginInstance) {
            throw new PluginNotFoundException("Plugin {$plugin} not found");
        }

        if ($pluginInstance->isCritical()) {
            throw new CriticalPluginException(
                "Cannot disable critical plugin {$plugin}"
            );
        }

        return $this->security->executeCriticalOperation(
            new DisablePluginOperation($pluginInstance, $this->cache),
            SecurityContext::fromRequest()
        );
    }
}
