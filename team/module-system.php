<?php

namespace App\Core\Modules;

use Illuminate\Support\Facades\{Cache, DB, Event};
use App\Core\Security\CoreSecurityManager;
use App\Core\Interfaces\{ModuleInterface, ValidationInterface};

class ModuleManager implements ModuleInterface
{
    private CoreSecurityManager $security;
    private ValidationInterface $validator;
    private array $activeModules = [];
    private array $dependencies = [];

    public function register(string $moduleId, array $config): bool
    {
        return $this->security->executeCriticalOperation(
            new ModuleOperation('register', compact('moduleId', 'config'), function() use ($moduleId, $config) {
                DB::beginTransaction();
                try {
                    $this->validateModule($moduleId, $config);
                    $this->checkDependencies($config['dependencies'] ?? []);
                    
                    $module = new Module([
                        'id' => $moduleId,
                        'config' => encrypt(json_encode($config)),
                        'status' => ModuleStatus::REGISTERED,
                        'version' => $config['version'],
                        'checksum' => $this->calculateChecksum($config)
                    ]);
                    
                    $module->save();
                    $this->updateDependencyGraph($moduleId, $config);
                    $this->validateSystemIntegrity();
                    
                    DB::commit();
                    return true;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    public function activate(string $moduleId): bool
    {
        return $this->security->executeCriticalOperation(
            new ModuleOperation('activate', ['moduleId' => $moduleId], function() use ($moduleId) {
                DB::beginTransaction();
                try {
                    $module = $this->getModule($moduleId);
                    $this->validateActivation($module);
                    
                    $module->status = ModuleStatus::ACTIVE;
                    $module->save();
                    
                    $this->loadModule($module);
                    $this->registerRoutes($module);
                    $this->registerEventListeners($module);
                    
                    $this->activeModules[$moduleId] = $module;
                    Cache::put("module:$moduleId:active", true, 3600);
                    
                    DB::commit();
                    return true;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw $e;
                }
            })
        );
    }

    private function validateModule(string $moduleId, array $config): void
    {
        if (!$this->validator->validateModuleConfig($config)) {
            throw new ModuleValidationException("Invalid module configuration");
        }

        if (!$this->validator->validateSecurityRequirements($config['security'] ?? [])) {
            throw new SecurityValidationException("Module failed security validation");
        }

        if (!$this->validator->validatePerformanceRequirements($config['performance'] ?? [])) {
            throw new PerformanceValidationException("Module failed performance validation");
        }
    }

    private function checkDependencies(array $dependencies): void
    {
        foreach ($dependencies as $dependency => $version) {
            if (!$this->isModuleCompatible($dependency, $version)) {
                throw new DependencyException("Incompatible module dependency: $dependency ($version)");
            }
        }
    }

    private function updateDependencyGraph(string $moduleId, array $config): void
    {
        if (empty($config['dependencies'])) {
            return;
        }

        $this->dependencies[$moduleId] = $config['dependencies'];
        
        if (!$this->validateDependencyGraph()) {
            unset($this->dependencies[$moduleId]);
            throw new CyclicDependencyException("Module would create dependency cycle");
        }
    }

    private function validateSystemIntegrity(): void
    {
        $modules = Module::where('status', ModuleStatus::ACTIVE)->get();
        
        foreach ($modules as $module) {
            $config = json_decode(decrypt($module->config), true);
            
            if (!$this->validateModuleIntegrity($module, $config)) {
                throw new IntegrityException("System integrity check failed");
            }
        }
    }

    private function validateModuleIntegrity(Module $module, array $config): bool
    {
        return $this->validateModuleFiles($module) &&
               $this->validateModuleDatabase($module) &&
               $this->validateModuleChecksum($module, $config);
    }

    private function loadModule(Module $module): void
    {
        $config = json_decode(decrypt($module->config), true);
        
        require_once $this->getModulePath($module->id) . '/bootstrap.php';
        
        $moduleClass = $config['moduleClass'];
        $instance = new $moduleClass($config);
        
        if (method_exists($instance, 'boot')) {
            $instance->boot();
        }
    }

    private function registerRoutes(Module $module): void
    {
        $routesFile = $this->getModulePath($module->id) . '/routes.php';
        
        if (file_exists($routesFile)) {
            require_once $routesFile;
        }
    }

    private function registerEventListeners(Module $module): void
    {
        $config = json_decode(decrypt($module->config), true);
        
        foreach ($config['events'] ?? [] as $event => $listeners) {
            foreach ($listeners as $listener) {
                Event::listen($event, $listener);
            }
        }
    }

    private function calculateChecksum(array $config): string
    {
        return hash('sha256', json_encode($config));
    }

    private function getModulePath(string $moduleId): string
    {
        return base_path("modules/$moduleId");
    }
}

class ModuleOperation implements CriticalOperation
{
    private string $type;
    private array $data;
    private \Closure $operation;

    public function __construct(string $type, array $data, \Closure $operation)
    {
        $this->type = $type;
        $this->data = $data;
        $this->operation = $operation;
    }

    public function execute(): mixed
    {
        return ($this->operation)();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getData(): array
    {
        return $this->data;
    }
}
