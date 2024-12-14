<?php

namespace App\Repositories;

use App\Models\Module;
use App\Repositories\Contracts\ModuleRepositoryInterface;
use Illuminate\Support\Collection;

class ModuleRepository extends BaseRepository implements ModuleRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['status', 'type'];

    public function __construct(Module $model)
    {
        parent::__construct($model);
    }

    public function install(array $data): ?Module
    {
        try {
            DB::beginTransaction();

            $module = $this->create([
                'name' => $data['name'],
                'slug' => Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'version' => $data['version'],
                'type' => $data['type'] ?? 'general',
                'status' => 'inactive',
                'dependencies' => $data['dependencies'] ?? [],
                'config' => $data['config'] ?? [],
            ]);

            $this->processResources($module, $data['resources'] ?? []);

            DB::commit();
            $this->clearModelCache();
            
            return $module;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to install module: ' . $e->getMessage());
            return null;
        }
    }

    public function getActive(): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey('active'),
                $this->cacheTTL,
                fn() => $this->model->where('status', 'active')->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get active modules: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function toggleStatus(int $moduleId): bool
    {
        try {
            DB::beginTransaction();

            $module = $this->find($moduleId);
            $module->update(['status' => $module->status === 'active' ? 'inactive' : 'active']);

            DB::commit();
            $this->clearModelCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to toggle module status: ' . $e->getMessage());
            return false;
        }
    }

    protected function processResources(Module $module, array $resources): void
    {
        foreach ($resources as $type => $items) {
            switch ($type) {
                case 'migrations':
                    $this->runMigrations($items);
                    break;
                case 'seeds':
                    $this->runSeeds($items);
                    break;
                case 'assets':
                    $this->publishAssets($items);
                    break;
            }
        }
    }
}
