<?php

namespace App\Modules\Services;

use App\Modules\Contracts\ModuleInterface;
use App\Modules\Exceptions\{ModuleException, ModuleNotFoundException, ModuleDependencyException};
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class ModuleManager
{
    protected Collection $modules;
    protected string $modulePath;
    protected array $enabled = [];

    public function __construct()
    {
        $this->modules = new Collection();
        $this->modulePath = app_path('Modules');
        $this->loadModules();
    }

    public function getModule(string $name): ?ModuleInterface
    {
        return $this->modules->get($name);
    }

    public function getAllModules(): Collection
    {
        return $this->modules;
    }

    public function getEnabledModules(): Collection
    {
        return $this->modules->filter(fn($module) => $module->getStatus() === 'enabled');
    }

    public function install(string $name): bool
    {
        $module = $this->findModule($name);
        
        $this->validateDependencies($module);
        
        return $module->install();
    }

    public function uninstall(string $name): bool
    {
        $module = $this->findModule($name);
        
        $this->validateDependents($module);
        
        return $module->uninstall();
    }

    public function enable(string $name): bool
    {
        $module = $this->findModule($name);
        
        if ($module->getStatus() !== 'installed') {
            throw new ModuleException("Module {$name} must be installed first");
        }
        
        $this->validateDependencies($module);
        
        return $module->enable();
    }

    public function disable(string $name): bool
    {
        $module = $this->findModule($name);
        
        $this->validateDependents($module);
        
        return $module->disable();
    }

    protected function loadModules(): void
    {
        if (!File::isDirectory($this->modulePath)) {
            return;
        }

        $modules = File::directories($this->modulePath);

        foreach ($modules as $modulePath) {
            $this->loadModule($modulePath);
        }
    }

    protected function loadModule(string $path): void
    {
        $moduleName = basename($path);
        $moduleClass = "App\\Modules\\{$moduleName}\\{$moduleName}Module";

        if (class_exists($moduleClass)) {
            $module = new $moduleClass();
            $this->modules->put($module->getName(), $module);
        }
    }

    protected function findModule(string $name): ModuleInterface
    {
        $module = $this->getModule($name);

        if (!$module) {
            throw new ModuleNotFoundException("Module {$name} not found");
        }

        return $module;
    }

    protected function validateDependencies(ModuleInterface $module): void
    {
        foreach ($module->getDependencies() as $dependency) {
            $dependencyModule = $this->getModule($dependency);

            if (!$dependencyModule) {
                throw new ModuleDependencyException("Required dependency {$dependency} not found");
            }

            if ($dependencyModule->getStatus() !== 'enabled') {
                throw new ModuleDependencyException("Required dependency {$dependency} is not enabled");
            }
        }
    }

    protected function validateDependents(ModuleInterface $module): void
    {
        $dependents = $this->findDependents($module->getName());

        if ($dependents->isNotEmpty()) {
            $dependentNames = $dependents->pluck('name')->join(', ');
            throw new ModuleDependencyException(
                "Cannot modify module: following modules depend on it: {$dependentNames}"
            );
        }
    }

    protected function findDependents(string $moduleName): Collection
    {
        return $this->modules->filter(function ($module) use ($moduleName) {
            return in_array($moduleName, $module->getDependencies());
        });
    }
}
