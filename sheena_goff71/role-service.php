<?php

namespace App\Core\Role\Services;

use App\Core\Role\Models\Role;
use App\Core\Role\Repositories\RoleRepository;
use App\Core\Role\Events\{RoleCreated, RoleUpdated, RoleDeleted};
use Illuminate\Support\Facades\{DB, Event};

class RoleService
{
    public function __construct(
        private RoleRepository $repository,
        private RoleValidator $validator
    ) {}

    public function create(array $data): Role
    {
        $this->validator->validateCreate($data);

        return DB::transaction(function () use ($data) {
            $role = $this->repository->create($data);
            
            if (!empty($data['permissions'])) {
                $role->permissions()->sync($data['permissions']);
            }

            event(new RoleCreated($role));
            
            return $role;
        });
    }

    public function update(Role $role, array $data): Role
    {
        $this->validator->validateUpdate($role, $data);

        return DB::transaction(function () use ($role, $data) {
            $role = $this->repository->update($role, $data);

            if (isset($data['permissions'])) {
                $role->permissions()->sync($data['permissions']);
            }

            event(new RoleUpdated($role));
            
            return $role;
        });
    }

    public function delete(Role $role): bool
    {
        $this->validator->validateDelete($role);

        return DB::transaction(function () use ($role) {
            $deleted = $this->repository->delete($role);
            
            if ($deleted) {
                event(new RoleDeleted($role));
            }
            
            return $deleted;
        });
    }

    public function assignPermissions(Role $role, array $permissions): void
    {
        $this->validator->validatePermissions($permissions);
        $role->permissions()->sync($permissions);
    }

    public function removePermission(Role $role, string $permission): void
    {
        $this->validator->validatePermission($permission);
        $role->permissions()->detach($permission);
    }

    public function getByName(string $name): ?Role
    {
        return $this->repository->findByName($name);
    }

    public function listAll(array $filters = []): Collection
    {
        return $this->repository->listWithFilters($filters);
    }

    public function getUsersInRole(Role $role): Collection
    {
        return $role->users;
    }

    public function getPermissions(Role $role): Collection
    {
        return $role->permissions;
    }

    public function syncUsers(Role $role, array $userIds): void
    {
        $this->validator->validateUsers($userIds);
        $role->users()->sync($userIds);
    }

    public function getHierarchy(): array
    {
        return $this->repository->getRoleHierarchy();
    }
}
