<?php

namespace App\Repositories;

use App\Models\Permission;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PermissionRepository implements PermissionRepositoryInterface
{
    protected Permission $model;
    protected int $cacheTTL = 3600;

    public function __construct(Permission $model)
    {
        $this->model = $model;
    }

    public function create(array $data): ?int
    {
        try {
            DB::beginTransaction();

            $permission = $this->model->create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? str($data['name'])->slug(),
                'description' => $data['description'] ?? null,
                'module' => $data['module'] ?? 'core',
                'group' => $data['group'] ?? 'general',
            ]);

            $this->clearPermissionCache();
            DB::commit();

            return $permission->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create permission: ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            DB::beginTransaction();

            $permission = $this->model->findOrFail($id);
            
            $updateData = [
                'name' => $data['name'] ?? $permission->name,
                'slug' => $data['slug'] ?? ($data['name'] ? str($data['name'])->slug() : $permission->slug),
                'description' => $data['description'] ?? $permission->description,
                'module' => $data['module'] ?? $permission->module,
                'group' => $data['group'] ?? $permission->group,
            ];

            $permission->update($updateData);

            $this->clearPermissionCache();
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update permission: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $permission = $this->model->findOrFail($id);
            
            // Remove from all users
            $permission->users()->detach();
            
            // Remove from all roles
            $permission->roles()->detach();

            $permission->delete();

            $this->clearPermissionCache();
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete permission: ' . $e->getMessage());
            return false;
        }
    }

    public function get(int $id): ?array
    {
        try {
            return Cache::remember(
                "permission.{$id}",
                $this->cacheTTL,
                fn() => $this->model->findOrFail($id)->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get permission: ' . $e->getMessage());
            return null;
        }
    }

    public function getBySlug(string $slug): ?array
    {
        try {
            return Cache::remember(
                "permission.slug.{$slug}",
                $this->cacheTTL,
                fn() => $this->model->where('slug', $slug)
                    ->firstOrFail()
                    ->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get permission by slug: ' . $e->getMessage());
            return null;
        }
    }

    public function getByModule(string $module): Collection
    {
        try {
            return Cache::remember(
                "permissions.module.{$module}",
                $this->cacheTTL,
                fn() => $this->model->where('module', $module)->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get permissions by module: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function getGrouped(): Collection
    {
        try {
            return Cache::remember(
                'permissions.grouped',
                $this->cacheTTL,
                fn() => $this->model->get()->groupBy('module')
            );
        } catch (\Exception $e) {
            Log::error('Failed to get grouped permissions: ' . $e->getMessage());
            return new Collection();
        }
    }

    protected function clearPermissionCache(): void
    {
        Cache::tags(['permissions'])->flush();
    }
}
