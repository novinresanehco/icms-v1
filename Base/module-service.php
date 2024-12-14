<?php

namespace App\Core\Services;

use App\Core\Models\Module;
use App\Core\Services\Contracts\ModuleServiceInterface;
use App\Core\Repositories\Contracts\ModuleRepositoryInterface;
use App\Core\Exceptions\ModuleInstallationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class ModuleService implements ModuleServiceInterface
{
    public function __construct(
        private ModuleRepositoryInterface $repository
    ) {}

    public function install(string $moduleSlug): Module
    {
        $module = $this->repository->findBySlug($moduleSlug);
        
        if (!$this->validateDependencies($module)) {
            throw new ModuleInstallationException("Module dependencies not satisfied");
        }

        try {
            $this->runMigrations($module);
            $this->publishAssets($module);
            return $this->repository->activate($module->id);
        } catch (\Exception $e) {
            throw new ModuleInstallationException("Installation failed: {$e->getMessage()}");
        }
    }

    public function uninstall(string $moduleSlug): bool
    {
        $module = $this->repository->findBySlug($moduleSlug);
        
        if ($this->hasDependents($module)) {
            throw new ModuleInstallationException("Module has active dependents");
        }

        try {
            $this->rollbackMigrations($module);
            $this->removeAssets($module);
            return $this->repository->deactivate($module->id);
        } catch (\Exception $e) {
            throw new ModuleInstallationException("Uninstallation failed: {$e->getMessage()}");
        }
    }

    private function validateDependencies(Module $module): bool
    {
        foreach ($module->dependencies ?? [] as $dependency) {
            $dependencyModule = $this->repository->findBySlug($dependency);
            if (!$dependencyModule?->is_active) {
                return false;
            }
        }
        return true;
    }

    private function hasDependents(Module $module): bool
    {
        return Module::where('is_active', true)
            ->whereJsonContains('dependencies', $module->slug)
            ->exists();
    }

    private function runMigrations(Module $module): void
    {
        $path = $module->getModulePath() . '/database/migrations';
        if (File::exists($path)) {
            Artisan::call('migrate', ['--path' => $path]);
        }
    }

    private function rollbackMigrations(Module $module): void
    {
        $path = $module->getModulePath() . '/database/migrations';
        if (File::exists($path)) {
            Artisan::call('migrate:rollback', ['--path' => $path]);
        }
    }

    private function publishAssets(Module $module): void
    {
        $source = $module->getModulePath() . '/public';
        $destination = public_path('modules/' . $module->slug);

        if (File::exists($source)) {
            File::copyDirectory($source, $destination);
        }
    }

    private function removeAssets(Module $module): void
    {
        $path = public_path('modules/' . $module->slug);
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }
}
