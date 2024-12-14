<?php

namespace App\Repositories;

use App\Models\Module;
use App\Repositories\Contracts\ModuleRepositoryInterface;
use Illuminate\Support\Collection;

class ModuleRepository extends BaseRepository implements ModuleRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['status', 'type'];
    protected array $relationships = ['dependencies', 'permissions'];

    public function getActive(): Collection
    {
        return Cache::remember(
            $this->getCacheKey('active'),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('status', 'active')
                ->orderBy('priority')
                ->get()
        );
    }

    public function install(array $data): Module
    {
        try {
            DB::beginTransaction();
            
            $module = $this->create(array_merge($data, ['status' => 'installing']));
            
            if (!empty($data['dependencies'])) {
                $module->dependencies()->sync($data['dependencies']);
            }
            
            if (!empty($data['permissions'])) {
                app(PermissionRepository::class)->createForModule(
                    $module->name,
                    $data['permissions']
                );
            }
            
            $module->update(['status' => 'active']);
            
            DB::commit();
            $this->clearModelCache();
            return $module->load($this->relationships);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to install module: {$e->getMessage()}");
        }
    }

    public function uninstall(int $id): void
    {
        try {
            DB::beginTransaction();
            
            $module = $this->findOrFail($id);
            
            // Remove permissions
            Permission::where('module', $module->name)->delete();
            
            // Remove dependencies
            $module->dependencies()->detach();
            
            // Delete module
            $module->delete();
            
            DB::commit();
            $this->clearModelCache();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to uninstall module: {$e->getMessage()}");
        }
    }

    public function getDependents(int $moduleId): Collection
    {
        return $this->model->whereHas('dependencies', function($query) use ($moduleId) {
            $query->where('dependency_id', $moduleId);
        })->get();
    }
}
