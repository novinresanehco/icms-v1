<?php

namespace App\Core\Plugin\Repository;

use App\Core\Plugin\Models\Plugin;
use App\Core\Plugin\DTO\PluginData;
use App\Core\Plugin\Events\PluginInstalled;
use App\Core\Plugin\Events\PluginUninstalled;
use App\Core\Plugin\Events\PluginEnabled;
use App\Core\Plugin\Events\PluginDisabled;
use App\Core\Plugin\Events\PluginUpdated;
use App\Core\Plugin\Services\PluginManager;
use App\Core\Plugin\Services\DependencyResolver;
use App\Core\Plugin\Exceptions\PluginNotFoundException;
use App\Core\Plugin\Exceptions\PluginIncompatibleException;
use App\Core\Plugin\Exceptions\PluginDependencyException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;

class PluginRepository extends BaseRepository implements PluginRepositoryInterface
{
    protected const CACHE_KEY = 'plugins';
    protected const CACHE_TTL = 3600; // 1 hour

    protected PluginManager $pluginManager;
    protected DependencyResolver $dependencyResolver;

    public function __construct(
        CacheManagerInterface $cache,
        PluginManager $pluginManager,
        DependencyResolver $dependencyResolver
    ) {
        parent::__construct($cache);
        $this->pluginManager = $pluginManager;
        $this->dependencyResolver = $dependencyResolver;
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return Plugin::class;
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

    public function findByIdentifier(string $identifier): ?Plugin
    {
        return $this->cache->remember(
            $this->getCacheKey("identifier:{$identifier}"),
            fn() => $this->model->where('identifier', $identifier)->first()
        );
    }

    public function install(PluginData $data): Plugin
    {
        // Validate plugin data
        $errors = $data->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Invalid plugin data: ' . json_encode($errors));
        }

        // Check dependencies
        $this->checkDependencies($data->dependencies);

        DB::beginTransaction();
        try {
            // Create plugin record
            $plugin = $this->model->create([
                'name' => $data->name,
                'identifier' => $data->identifier,
                'description' => $data->description,
                'version' => $data->version,
                'author' => $data->author,
                'homepage' => $data->homepage,
                'dependencies' => $data->dependencies,
                'config' => $data->config ?? [],
                'is_enabled' => false,
                'priority' => $this->getNextPriority(),
            ]);

            // Run installation tasks
            $this->pluginManager->install($plugin);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new PluginInstalled($plugin));

            DB::commit();
            return $plugin->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function uninstall(int $id): bool
    {
        DB::beginTransaction();
        try {
            $plugin = $this->findOrFail($id);

            // Check if other plugins depend on this one
            $dependents = $this->getPluginDependents($plugin->identifier);
            if ($dependents->isNotEmpty()) {
                throw new PluginDependencyException('Cannot uninstall plugin: other plugins depend on it');
            }

            // Run uninstallation tasks
            $this->pluginManager->uninstall($plugin);

            // Delete plugin record
            $deleted = $plugin->delete();

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new PluginUninstalled($plugin));

            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function enable(int $id): Plugin
    {
        DB::beginTransaction();
        try {
            $plugin = $this->findOrFail($id);

            // Check dependencies
            $this->checkDependencies($plugin->dependencies);

            // Enable plugin
            $plugin->update(['is_enabled' => true]);

            // Run activation tasks
            $this->pluginManager->enable($plugin);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new PluginEnabled($plugin));

            DB::commit();
            return $plugin->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function disable(int $id): Plugin
    {
        DB::beginTransaction();
        try {
            $plugin = $this->findOrFail($id);

            // Check if other enabled plugins depend on this one
            $dependents = $this->getEnabledPluginDependents($plugin->identifier);
            if ($dependents->isNotEmpty()) {
                throw new PluginDependencyException('Cannot disable plugin: other enabled plugins depend on it');
            }

            // Disable plugin
            $plugin->update(['is_enabled' => false]);

            // Run deactivation tasks
            $this->pluginManager->disable($plugin);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new PluginDisabled($plugin));

            DB::commit();
            return $plugin->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateConfig(int $id, array $config): Plugin
    {
        DB::beginTransaction();
        try {
            $plugin = $this->findOrFail($id);
            
            // Update config
            $plugin->update([
                'config' => array_merge($plugin->config ?? [], $config)
            ]);

            // Clear cache
            $this->clearCache();

            DB::commit();
            return $plugin->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getByDependency(string $dependency): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("dependency:{$dependency}"),
            fn() => $this->model->where('dependencies', 'like', "%\"{$dependency}\"%")->get()
        );
    }

    public function checkCompatibility(int $id): array
    {
        $plugin = $this->findOrFail($id);
        return $this->pluginManager->checkCompatibility($plugin);
    }

    public function getUpdateInfo(int $id): ?array
    {
        $plugin = $this->findOrFail($id);
        return $this->pluginManager->getUpdateInfo($plugin);
    }

    public function update(int $id): Plugin
    {
        DB::beginTransaction();
        try {
            $plugin = $this->findOrFail($id);
            
            // Get update info
            $updateInfo = $this->getUpdateInfo($id);
            if (!$updateInfo) {
                throw new \RuntimeException('No update available');
            }

            // Update plugin
            $plugin = $this->pluginManager->update($plugin, $updateInfo);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new PluginUpdated($plugin));

            DB::commit();
            return $plugin->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getHooks(int $id): array
    {
        $plugin = $this->findOrFail($id);
        return $this->pluginManager->getHooks($plugin);
    }

    public function runMigrations(int $id, string $direction = 'up'): bool
    {
        $plugin = $this->findOrFail($id);
        return $this->pluginManager->runMigrations($plugin, $direction);
    }

    protected function checkDependencies(array $dependencies): void
    {
        foreach ($dependencies as $dependency => $constraint) {
            if (!$this->dependencyResolver->checkDependency($dependency, $constraint)) {
                throw new PluginDependencyException(
                    "Dependency not satisfied: {$dependency} ({$constraint})"
                );
            }
        }
    }

    protected function getPluginDependents(string $identifier): Collection
    {
        return $this->model->where('dependencies', 'like', "%\"{$identifier}\"%")->get();
    }

    protected function getEnabledPluginDependents(string $identifier): Collection
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
