namespace App\Core\Plugin;

class PluginManager implements PluginManagerInterface 
{
    private SecurityManager $security;
    private PluginRegistry $registry;
    private ContainerInterface $container;
    private EventDispatcher $events;
    private ValidationService $validator;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        PluginRegistry $registry,
        ContainerInterface $container,
        EventDispatcher $events,
        ValidationService $validator,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->registry = $registry;
        $this->container = $container;
        $this->events = $events;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function registerPlugin(Plugin $plugin): void 
    {
        $this->security->executeCriticalOperation(new class($plugin, $this->registry, $this->validator) implements CriticalOperation {
            private Plugin $plugin;
            private PluginRegistry $registry;
            private ValidationService $validator;

            public function __construct(Plugin $plugin, PluginRegistry $registry, ValidationService $validator) 
            {
                $this->plugin = $plugin;
                $this->registry = $registry;
                $this->validator = $validator;
            }

            public function execute(): OperationResult 
            {
                $this->validator->validatePlugin($this->plugin);
                $this->verifyDependencies();
                $this->verifySecurityConstraints();
                
                $this->registry->register($this->plugin);
                
                return new OperationResult(true);
            }

            private function verifyDependencies(): void 
            {
                foreach ($this->plugin->getDependencies() as $dependency) {
                    if (!$this->registry->hasPlugin($dependency)) {
                        throw new PluginDependencyException("Missing dependency: {$dependency}");
                    }
                }
            }

            private function verifySecurityConstraints(): void 
            {
                if (!$this->validator->validateSecurityPolicy($this->plugin)) {
                    throw new SecurityException('Plugin violates security policy');
                }
            }

            public function getValidationRules(): array 
            {
                return [
                    'name' => 'required|string|max:255',
                    'version' => 'required|string',
                    'dependencies' => 'array'
                ];
            }

            public function getData(): array 
            {
                return [
                    'name' => $this->plugin->getName(),
                    'version' => $this->plugin->getVersion()
                ];
            }

            public function getRequiredPermissions(): array 
            {
                return ['plugin.register'];
            }

            public function getRateLimitKey(): string 
            {
                return "plugin:register:{$this->plugin->getName()}";
            }
        });
    }

    public function loadPlugin(string $name): Plugin 
    {
        return $this->security->executeCriticalOperation(new class($name, $this->registry, $this->container) implements CriticalOperation {
            private string $name;
            private PluginRegistry $registry;
            private ContainerInterface $container;

            public function __construct(string $name, PluginRegistry $registry, ContainerInterface $container) 
            {
                $this->name = $name;
                $this->registry = $registry;
                $this->container = $container;
            }

            public function execute(): OperationResult 
            {
                $plugin = $this->registry->getPlugin($this->name);
                
                $this->loadDependencies($plugin);
                $plugin->boot($this->container);
                
                return new OperationResult($plugin);
            }

            private function loadDependencies(Plugin $plugin): void 
            {
                foreach ($plugin->getDependencies() as $dependency) {
                    $this->registry->getPlugin($dependency)->boot($this->container);
                }
            }

            public function getValidationRules(): array 
            {
                return ['name' => 'required|string|max:255'];
            }

            public function getData(): array 
            {
                return ['name' => $this->name];
            }

            public function getRequiredPermissions(): array 
            {
                return ['plugin.load'];
            }

            public function getRateLimitKey(): string 
            {
                return "plugin:load:{$this->name}";
            }
        });
    }

    public function unloadPlugin(string $name): void 
    {
        $this->security->executeCriticalOperation(new class($name, $this->registry) implements CriticalOperation {
            private string $name;
            private PluginRegistry $registry;

            public function __construct(string $name, PluginRegistry $registry) 
            {
                $this->name = $name;
                $this->registry = $registry;
            }

            public function execute(): OperationResult 
            {
                $plugin = $this->registry->getPlugin($this->name);
                $this->verifyNoActiveDependents($plugin);
                
                $plugin->shutdown();
                $this->registry->unregister($this->name);
                
                return new OperationResult(true);
            }

            private function verifyNoActiveDependents(Plugin $plugin): void 
            {
                $dependents = $this->registry->getDependentPlugins($plugin->getName());
                if (!empty($dependents)) {
                    throw new PluginException('Plugin has active dependents');
                }
            }

            public function getValidationRules(): array 
            {
                return ['name' => 'required|string|max:255'];
            }

            public function getData(): array 
            {
                return ['name' => $this->name];
            }

            public function getRequiredPermissions(): array 
            {
                return ['plugin.unload'];
            }

            public function getRateLimitKey(): string 
            {
                return "plugin:unload:{$this->name}";
            }
        });
    }

    public function getActivePlugins(): array 
    {
        return $this->security->executeCriticalOperation(new class($this->registry) implements CriticalOperation {
            private PluginRegistry $registry;

            public function __construct(PluginRegistry $registry) 
            {
                $this->registry = $registry;
            }

            public function execute(): OperationResult 
            {
                return new OperationResult($this->registry->getActivePlugins());
            }

            public function getValidationRules(): array 
            {
                return [];
            }

            public function getData(): array 
            {
                return [];
            }

            public function getRequiredPermissions(): array 
            {
                return ['plugin.list'];
            }

            public function getRateLimitKey(): string 
            {
                return 'plugin:list';
            }
        });
    }
}
