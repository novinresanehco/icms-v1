<?php

namespace App\Core\Auth\Repository;

use App\Core\Auth\Models\Permission;
use App\Core\Auth\DTO\PermissionData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface PermissionRepositoryInterface extends RepositoryInterface
{
    /**
     * Find permission by name.
     *
     * @param string $name
     * @return Permission|null
     */
    public function findByName(string $name): ?Permission;

    /**
     * Create new permission.
     *
     * @param PermissionData $data
     * @return Permission
     */
    public function createPermission(PermissionData $data): Permission;

    /**
     * Update permission.
     *
     * @param int $id
     * @param PermissionData $data
     * @return Permission
     */
    public function updatePermission(int $id, PermissionData $data): Permission;

    /**
     * Get permissions by group.
     *
     * @param string $group
     * @return Collection
     */
    public function getByGroup(string $group): Collection;

    /**
     * Get all permissions grouped.
     *
     * @return array
     */
    public function getAllGrouped(): array;

    /**
     * Get permissions usage statistics.
     *
     * @param int $permissionId
     * @return array
     */
    public function getUsageStats(int $permissionId): array;

    /**
     * Sync permissions to role.
     *
     * @param int $roleId
     * @param array $permissions
     * @return void
     */
    public function syncToRole(int $roleId, array $permissions): void;

    /**
     * Get permissions by role.
     *
     * @param int $roleId
     * @return Collection
     */
    public function getByRole(int $roleId): Collection;

    /**
     * Register new permission group.
     *
     * @param string $group
     * @param array $permissions
     * @return Collection
     */
    public function registerGroup(string $group, array $permissions): Collection;

    /**
     * Check if permission is in use.
     *
     * @param int $permissionId
     * @return bool
     */
    public function isInUse(int $permissionId): bool;
}
