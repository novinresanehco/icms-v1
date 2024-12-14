namespace App\Core\Plugin;

class PluginManager implements PluginInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private SandboxManager $sandbox;
    private DependencyResolver $resolver;
    private PluginRepository $repository;
    private array $config;

    public function loadPlugin(string $identifier): Plugin 
    {
        return $this->security->executeCriticalOperation(
            new LoadPluginOperation($identifier),
            function() use ($identifier) {
                // Validate plugin
                $plugin = $this->validatePlugin($identifier);
                
                // Check dependencies
                $this->checkDependencies($plugin);
                
                // Create sandbox
                $sandbox = $this->createPluginSandbox($plugin);
                
                try {
                    // Load plugin in sandbox
                    $instance = $sandbox->load($plugin);
                    
                    // Register event listeners
                    $this->registerEventListeners($instance);
                    
                    // Initialize plugin
                    $instance->initialize($this->config['plugin_config']);
                    
                    return $instance;
                    
                } catch (\Exception $e) {
                    // Cleanup sandbox
                    $sandbox->cleanup();
                    throw $e;
                }
            }
        );
    }

    public function installPlugin(string $path): void 
    {
        $this->security->executeCriticalOperation(
            new InstallPluginOperation($path),
            function() use ($path) {
                // Verify package
                $package = $this->verifyPluginPackage($path);
                
                // Extract to temp
                $tempPath = $this->extractToTemp($package);
                
                try {
                    // Validate structure
                    $this->validatePluginStructure($tempPath);
                    
                    // Scan for malicious code
                    $this->scanForMaliciousCode($tempPath);
                    
                    // Verify dependencies
                    $this->verifyDependencies($tempPath);
                    
                    // Install plugin
                    $this->performInstallation($tempPath);
                    
                } finally {
                    // Cleanup temp
                    $this->cleanupTemp($tempPath);
                }
            }
        );
    }

    public function uninstallPlugin(string $identifier): void 
    {
        $this->security->executeCriticalOperation(
            new UninstallPluginOperation($identifier),
            function() use ($identifier) {
                // Get plugin
                $plugin = $this->repository->findOrFail($identifier);
                
                // Check dependencies
                $this->checkDependentPlugins($plugin);
                
                // Deactivate plugin
                $this->deactivatePlugin($plugin);
                
                // Remove files
                $this->removePluginFiles($plugin);
                
                // Remove database entries
                $this->removePluginData($plugin);
                
                // Update plugin registry
                $this->updateRegistry($plugin, 'uninstalled');
            }
        );
    }

    protected function validatePlugin(string $identifier): Plugin 
    {
        $plugin = $this->repository->findOrFail($identifier);
        
        if (!$plugin->isValid()) {
            throw new InvalidPluginException();
        }

        if (!$plugin->isCompatible()) {
            throw new IncompatiblePluginException();
        }

        return $plugin;
    }

    protected function checkDependencies(Plugin $plugin): void 
    {
        $missing = $this->resolver->findMissingDependencies($plugin);
        
        if (!empty($missing)) {
            throw new MissingDependenciesException($missing);
        }
    }

    protected function createPluginSandbox(Plugin $plugin): PluginSandbox 
    {
        return $this->sandbox->create([
            'memory_limit' => $this->config['plugin_memory_limit'],
            'execution_time' => $this->config['plugin_timeout'],
            'allowed_classes' => $this->getAllowedClasses($plugin),
            'allowed_functions' => $this->getAllowedFunctions($plugin),
            'allowed_constants' => $this->getAllowedConstants($plugin)
        ]);
    }

    protected function registerEventListeners(Plugin $instance): void 
    {
        foreach ($instance->getEventListeners() as $event => $listener) {
            if ($this->isEventAllowed($event)) {
                event()->listen($event, [$instance, $listener]);
            }
        }
    }

    protected function verifyPluginPackage(string $path): PluginPackage 
    {
        if (!file_exists($path)) {
            throw new PluginNotFoundException();
        }

        $package = new PluginPackage($path);
        
        if (!$package->isValid()) {
            throw new InvalidPackageException();
        }

        return $package;
    }

    protected function validatePluginStructure(string $path): void 
    {
        $requiredFiles = [
            'plugin.json',
            'Plugin.php',
            'composer.json'
        ];

        foreach ($requiredFiles as $file) {
            if (!file_exists("$path/$file")) {
                throw new InvalidStructureException();
            }
        }

        $this->validatePluginJson("$path/plugin.json");
    }

    protected function scanForMaliciousCode(string $path): void 
    {
        $scanner = $this->security->getCodeScanner();
        
        $results = $scanner->scan($path);
        
        if ($results->hasMaliciousCode()) {
            throw new MaliciousCodeException($results->getFindings());
        }
    }

    protected function performInstallation(string $tempPath): void 
    {
        DB::beginTransaction();
        
        try {
            // Copy files
            $this->copyPluginFiles($tempPath);
            
            // Register plugin
            $this->registerPlugin($tempPath);
            
            // Run migrations
            $this->runPluginMigrations($tempPath);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function isEventAllowed(string $event): bool 
    {
        return in_array($event, $this->config['allowed_events']);
    }

    protected function checkDependentPlugins(Plugin $plugin): void 
    {
        $dependents = $this->resolver->findDependentPlugins($plugin);
        
        if (!empty($dependents)) {
            throw new HasDependentsException($dependents);
        }
    }
}
