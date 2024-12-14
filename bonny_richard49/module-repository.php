<?php

namespace App\Core\Module\Repository;

use App\Core\Module\Models\Module;
use App\Core\Module\DTO\ModuleData;
use App\Core\Module\Events\ModuleInstalled;
use App\Core\Module\Events\ModuleUninstalled;
use App\Core\Module\Events\ModuleEnabled;
use App\Core\Module\Events\ModuleDisabled;
use App\Core\Module\Services\ModuleLoader;
use App\Core\Module\Services\DependencyResolver;
use App\Core\Module\Exceptions\ModuleNotFoundException;
use App\Core\Module\Exceptions\ModuleIncompatibleException;
use App\Core\Module\Exceptions\ModuleDependencyException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class ModuleRepository extends BaseRepository implements ModuleRepositoryInterface
{
    protected const CACHE_KEY = 'modules';
    protected const CACHE_TTL = 3600; // 1 hour

    protected ModuleLoader $moduleLoader;
    protected DependencyResolver $dependencyResolver;

    public function __construct(
        CacheManagerInterface $cache,
        ModuleLoader $moduleLoader,
        DependencyResolver $dependencyResolver
    ) {
        parent::__construct($cache);
        $this->moduleLoader = $moduleLoader;
        $this->dependencyResolver = $dependencyResolver;
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return Module::class;
    }

    public function getActive(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('active'),
            fn() => $this->model->where('is_enabled', true)
                               ->orderBy('priority')
                               ->get()
        );
    }

    public function findByIdentifier(string $identifier): ?Module
    {
        return $this->cache->remember(
            $this->getCacheKey("identifier:{$identifier}"),
            fn() => $this->model->where('identifier', $identifier)->first()
        );
    }

    public function install(ModuleData $data): Module
    {
        // Validate module data
        $errors = $data->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Invalid module data: ' . json_encode($errors));
        }

        // Check dependencies
        $this->checkDependencies($data->dependencies);

        DB::beginTransaction();
        try {
            // Create module record
            $module = $this->model->create([
                'name' => $data->name,
                'identifier' => $data->identifier,
                'description' => $data->description,
                'version' => $data->version,
                'dependencies' => $data->dependencies,
                'config' => $data->config ?? [],
                'hooks' => $data->hooks ?? [],
                'providers' => $data->providers ?? [],
                'is_enabled' => false,
                'priority' => $this->getNextPriority(),
            ]);

            // Register module services
            $this->moduleLoader->registerServices($module);

            // Run migrations
            if (!empty($data->migrations)) {
                $this->runMigrations($module->id);
            }

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new ModuleInstalled($module));

            DB::commit();
            return $module->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function uninstall(int $id): bool
    {
        DB::beginTransaction();
        try {
            $module = $this->findOrFail($id);

            // Check if other modules depend on this one
            $dependents = $this->getModuleDependents($module->identifier);
            if ($dependents->isNotEmpty()) {
                throw new ModuleDependencyException('Cannot uninstall module: other modules depend on it');
            }

            // Run migrations rollback
            $this->runMigrations($id, 'down');

            // Delete module record
            $deleted = $module->delete();

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new ModuleUninstalled($module));

            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function enable(int $id): Module
    {
        DB::beginTransaction();
        try {
            $module = $this->findOrFail($id);

            // Check dependencies
            $this->checkDependencies($module->dependencies);

            // Enable module
            $module->update(['is_enabled' => true]);

            // Register module services
            $this->moduleLoader->registerServices($module);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new ModuleEnabled($module));

            DB::commit();
            return $module->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function disable(int $id): Module
    {
        DB::beginTransaction();
        try {
            $module = $this->findOrFail($id);

            // Check if other enabled modules depend on this one
            $dependents = $this->getEnabledModuleDependents($module->identifier);
            if ($dependents->isNotEmpty()) {
                throw new ModuleDependencyException('Cannot disable module: other enabled modules depend on it');
            }

            // Disable module
            $module->update(['is_enabled' => false]);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new ModuleDisabled($module));

            DB::commit();
            return $module->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateConfig(int $id, array $config): Module
    {
        DB::beginTransaction();
        try {
            $module = $this->findOrFail($id);
            
            // Update config
            $module->update([
                'config' => array_merge($module->config ?? [], $config)
            ]);

            // Clear cache
            $this->clearCache();

            DB::commit();
            return $module->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getModulesByHook(string $hook): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("hook:{$hook}"),
            fn() => $this->model->where('is_enabled', true)
                               ->where('hooks', 'like', "%{$hook}%")
                               ->orderBy('priority')
                               ->get()
        );
    }

    public function getDependencies(int $id): array
    {
        $module = $this->findOrFail($id);
        return $this->dependencyResolver->resolveDependencies($module->dependencies);
    }

    public function checkCompatibility(int $id): array
    {
        $module = $this->findOrFail($id);
        return $this->moduleLoader->checkCompatibility($module);
    }

    public function runMigrations(int $id, string $direction = 'up'): bool
    {
        $module = $this->findOrFail($id);
        return $this->moduleLoader->runMigrations($module, $direction);
    }

    public function getServices(int $id): array
    {
        $module = $this->findOrFail($id);
        return $this->moduleLoader->getServices($module);
    }

    public function getRoutes(int $id): array
    {
        $module = $this->findOrFail($id);
        return $this->moduleLoader->getRoutes($module);
    }

    protected function checkDependencies(array $dependencies): void
    {
        foreach ($dependencies as $dependency => $constraint) {
            if (!$this->dependencyResolver->checkDependency($dependency, $constraint)) {
                throw new ModuleDependencyException(
                    "Dependency not satisfied: {$dependency} ({$constraint})"
                );
            }
        }
    }

    protected function getModuleDependents(string $identifier): Collection
    {
        return $this->model->where('dependencies', 'like', "%\"{$identifier}\"%")->get();
    }

    protected function getEnabledModuleDependents(string $identifier): Collection
    {
        return $this->model
            ->where('dependencies', 'like', "%\"{$identifier}\"%")
            ->where('is_enabled', true)
            ->get();
    }

    protected function getNextPriority(): int
    {
        return $this->model->max('priority') + 10;
    }
}
