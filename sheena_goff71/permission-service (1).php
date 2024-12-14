<?php

namespace App\Core\Permission\Services;

use App\Core\Permission\Models\Permission;
use App\Core\Permission\Repositories\PermissionRepository;
use App\Core\Permission\Events\{PermissionCreated, PermissionUpdated, PermissionDeleted};
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Support\Collection;

class PermissionService
{
    public function __construct(
        private PermissionRepository $repository,
        private PermissionValidator $validator
    ) {}

    public function create(array $data): Permission
    {
        $this->validator->validateCreate($data);

        return DB::transaction(function () use ($data) {
            $permission = $this->repository->create($data);
            event(new PermissionCreated($permission));
            return $permission;
        });
    }

    public function update(Permission $permission, array $data): Permission
    {
        $this->validator->validateUpdate($permission, $data);

        return DB::transaction(function () use ($permission, $data) {
            $permission = $this->repository->update($permission, $data);
            event(new PermissionUpdated($permission));
            return $permission;
        });
    }

    public function delete(Permission $permission): bool
    {
        $this->validator->validateDelete($permission);

        return DB::transaction(function () use ($permission) {
            $deleted = $this->repository->delete($permission);
            
            if ($deleted) {
                event(new PermissionDeleted($permission));
            }
            
            return $deleted;
        });
    }

    public function getByName(string $name): ?Permission
    {
        return $this->repository->findByName($name);
    }

    public function listAll(array $filters = []): Collection
    {
        return $this->repository->listWithFilters($filters);
    }

    public function getByCategory(string $category): Collection
    {
        return $this->repository->findByCategory($category);
    }

    public function getRoles(Permission $permission): Collection
    {
        return $permission->roles;
    }

    public function getUsers(Permission $permission): Collection
    {
        return $permission->users;
    }

    public function syncRoles(Permission $permission, array $roleIds): void
    {
        $this->validator->validateRoles($roleIds);
        $permission->roles()->sync($roleIds);
    }

    public function syncUsers(Permission $permission, array $userIds): void
    {
        $this->validator->validateUsers($userIds);
        $permission->users()->sync($userIds);
    }

    public function bulkCreate(array $permissions): Collection
    {
        return DB::transaction(function () use ($permissions) {
            $created = collect();
            
            foreach ($permissions as $permissionData) {
                $this->validator->validateCreate($permissionData);
                $permission = $this->repository->create($permissionData);
                $created->push($permission);
            }
            
            return $created;
        });
    }

    public function getAccessMatrix(): array
    {
        return $this->repository->getAccessMatrix();
    }
}
