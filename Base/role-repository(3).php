<?php

namespace App\Repositories;

use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RoleRepository implements RoleRepositoryInterface
{
    protected Role $model;
    protected int $cacheTTL = 3600;

    public function __construct(Role $model)
    {
        $this->model = $model;
    }

    public function create(array $data): ?int
    {
        try {
            DB::beginTransaction();

            $role = $this->model->create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? str($data['name'])->slug(),
                'description' => $data['description'] ?? null,
                'level' => $data['level'] ?? 0,
                'metadata' => $data['metadata'] ?? [],
                'status' => $data['status'] ?? 'active',
            ]);

            if (!empty($data['permissions'])) {
                $role->permissions()->sync($data['permissions']);
            }

            $this->clearRoleCache();
            DB::commit();

            return $role->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create role: ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            DB::beginTransaction();

            $role = $this->model->findOrFail($id);
            
            $updateData = [
                'name' => $data['name'] ?? $role->name,
                'slug' => $data['slug'] ?? ($data['name'] ? str($data['name'])->slug() : $role->slug),
                'description' => $data['description'] ?? $role->description,
                'level' => $data['level'] ?? $role->level,
                'metadata' => array_merge($role->metadata ?? [], $data['metadata'] ?? []),
                'status' => $data['status'] ?? $role->status,
            ];

            $role->update($updateData);

            if (isset($data['permissions'])) {
                $role->permissions()->sync($data['permissions']);
            }

            $this->clearRoleCache($id);
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update role: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $role = $this->model->findOrFail($id);
            
            if ($role->slug === 'super-admin') {
                throw new \Exception('Cannot delete super-admin role');
            }

            // Remove all users from this role
            $role->users()->update(['role_id' => null]);
            
            // Remove all permissions
            $role->permissions()->detach();

            $role->delete();

            $this->clearRoleCache($id);
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete role: ' . $e->getMessage());
            return false;
        }
    }

    public function get(int $id): ?array
    {
        try {
            return Cache::remember(
                "role.{$id}",
                $this->cacheTTL,
                fn() => $this->model->with(['permissions'])
                    ->findOrFail($id)
                    ->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get role: ' . $e->getMessage());
            return null;
        }
    }

    public function getBySlug(string $slug): ?array
    {
        try {
            return Cache::remember(
                "role.slug.{$slug}",
                $this->cacheTTL,
                fn() => $this->model->with(['permissions'])
                    ->where('slug', $slug)
                    ->firstOrFail()
                    ->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get role by slug: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        try {
            $query = $this->model->query()
                ->with(['permissions']);

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['search'])) {
                $query->where(function ($q) use ($filters) {
                    $q->where('name', 'LIKE', "%{$filters['search']}%")
                        ->orWhere('description', 'LIKE', "%{$filters['search']}%");
                });
            }

            if (isset($filters['min_level'])) {
                $query->where('level', '>=', $filters['min_level']);
            }

            $orderBy = $filters['order_by'] ?? 'level';
            $orderDir = $filters['order_dir'] ?? 'desc';
            $query->orderBy($orderBy, $orderDir);

            return $query->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get paginated roles: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function assignPermissions(int $roleId, array $permissionIds): bool
    {
        try {
            DB::beginTransaction();

            $role = $this->model->findOrFail($roleId);
            $role->permissions()->sync($permissionIds);

            $this->clearRoleCache($roleId);
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to assign permissions to role: ' . $e->getMessage());
            return false;
        }
    }

    public function getPermissions(int $roleId): Collection
    {
        try {
            return Cache::remember(
                "role.{$roleId}.permissions",
                $this->cacheTTL,
                fn() => $this->model->findOrFail($roleId)
                    ->permissions()
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get role permissions: ' . $e->getMessage());
            return new Collection();
        }
    }

    protected function clearRoleCache(int $roleId = null): void
    {
        if ($roleId) {
            Cache::forget("role.{$roleId}");
            Cache::forget("role.{$roleId}.permissions");
            
            $role = $this->model->find($roleId);
            if ($role) {
                Cache::forget("role.slug.{$role->slug}");
            }
        }
        
        Cache::tags(['roles'])->flush();
    }
}
