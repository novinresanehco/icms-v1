<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface PermissionRepositoryInterface
{
    public function createPermission(array $data): ?int;
    
    public function updatePermission(int $permissionId, array $data): bool;
    
    public function deletePermission(int $permissionId): bool;
    
    public function getPermission(int $permissionId): ?array;
    
    public function getPermissionBySlug(string $slug): ?array;
    
    public function getAllPermissions(): Collection;
    
    public function getPermissionsByGroup(string $group): Collection;
    
    public function getUserPermissions(int $userId): Collection;
    
    public function assignDirectPermission(int $userId, int $permissionId): bool;
    
    public function removeDirectPermission(int $userId, int $permissionId): bool;
    
    public function userHasDirectPermission(int $userId, int $permissionId): bool;
}
