<?php

namespace App\Core\Auth\Repository;

use App\Core\Auth\Models\Role;
use App\Core\Auth\DTO\RoleData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

interface RoleRepositoryInterface extends RepositoryInterface
{
    /**
     * Find role by name.
     *
     * @param string $name
     * @return Role|null
     */
    public function findByName(string $name): ?Role;

    /**
     * Create new role.
     *
     * @param RoleData $data
     * @return Role
     */
    public function createRole(RoleData $data): Role;

    /**
     * Update role.
     *
     * @param int $id
     * @param RoleData $data
     * @return Role
     */
    public function updateRole(int $id, RoleData $data): Role;

    /**
     * Get roles with permissions.
     *
     * @return Collection
     */
    public function getRolesWithPermissions(): Collection;

    /**
     * Sync role permissions.
     *
     * @param int $roleId
     * @param array $permissions
     * @return Role
     */
    public function syncPermissions(int $roleId, array $permissions): Role;

    /**
     * Get role users count.
     *
     * @param int $roleId
     * @return int
     */
    public function getRoleUsersCount(int $roleId): int;

    /**
     * Check if role has permission.
     *
     * @param int $roleId
     * @param string $permission
     * @return bool
     */
    public function hasPermission(int $roleId, string $permission): bool;

    /**
     * Get role hierarchy.
     *
     * @return array
     */
    public function getRoleHierarchy(): array;

    /**
     * Get role descendants.
     *
     * @param int $roleId
     * @return Collection
     */
    public function getDescendants(int $roleId): Collection;

    /**
     * Get role ancestors.
     *
     * @param int $roleId
     * @return Collection
     */
    public function getAncestors(int $roleId): Collection;
}
