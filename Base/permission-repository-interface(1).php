<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Permission;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface PermissionRepositoryInterface
{
    public function find(int $id): ?Permission;
    
    public function findByName(string $name): ?Permission;
    
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    
    public function create(array $data): Permission;
    
    public function update(int $id, array $data): Permission;
    
    public function delete(int $id): bool;
    
    public function getByGuard(string $guard): Collection;
    
    public function assignToRole(string $permission, string $role): bool;
    
    public function revokeFromRole(string $permission, string $role): bool;
    
    public function syncRolePermissions(string $role, array $permissions): bool;
    
    public function getUserPermissions(int $userId): Collection;
    
    public function getModulePermissions(string $module): Collection;
}
