<?php

namespace App\Core\Role\Repositories;

use App\Core\Role\Models\Role;
use App\Core\Repository\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class RoleRepository extends BaseRepository
{
    public function model(): string
    {
        return Role::class;
    }

    public function create(array $data): Role
    {
        return Role::create($data);
    }

    public function update(Role $role, array $data): Role
    {
        $role->update($data);
        return $role->fresh();
    }

    public function delete(Role $role): bool
    {
        return $role->delete();
    }

    public function findByName(string $name): ?Role
    {
        return Role::where('name', $name)->first();
    }

    public function findWithRelations(int $id): ?Role
    {
        return Role::with(['users', 'permissions'])
                  ->find($id);
    }

    public function listWithFilters(array $filters = []): Collection
    {
        $query = Role::query();

        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        if (isset($filters['is_system'])) {
            $query->where('is_system', $filters['is_system']);
        }

        if (!empty($filters['permission'])) {
            $query->whereHas('permissions', function($q) use ($filters) {
                $q->where('name', $filters['permission']);
            });
        }

        return $query->with(['permissions'])
                    ->orderBy($filters['sort_by'] ?? 'level', $filters['sort_direction'] ?? 'asc')
                    ->get();
    }

    public function getRoleHierarchy(): array
    {
        return Role::orderBy('level')
                  ->get()
                  ->map(function ($role) {
                      return [
                          'id' => $role->id,
                          'name' => $role->name,
                          'level' => $role->level,
                          'user_count' => $role->getUserCount(),
                          'permissions' => $role->permissions->pluck('name')
                      ];
                  })
                  ->toArray();
    }

    public function getUserRoles(int $userId): Collection
    {
        return Role::whereHas('users', function($query) use ($userId) {
            $query->where('id', $userId);
        })->get();
    }

    public function getRolesByLevel(int $level): Collection
    {
        return Role::where('level', '<=', $level)
                  ->orderBy('level')
                  ->get();
    }

    public function getSystemRoles(): Collection
    {
        return Role::where('is_system', true)->get();
    }
}
