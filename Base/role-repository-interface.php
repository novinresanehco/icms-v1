<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface RoleRepositoryInterface
{
    /**
     * Create new role
     *
     * @param array $data
     * @return int|null
     */
    public function createRole(array $data): ?int;

    /**
     * Update role
     *
     * @param int $roleId
     * @param array $data
     * @return bool
     */
    public function updateRole(int $roleId, array $data): bool;

    /**
     * Delete role
     *
     * @param int $roleId
     * @return bool
     */
    public function deleteRole(int $roleId): bool;

    /**
     * Get role by ID
     *
     * @param int $roleId
     * @return array|null
     */
    public function getRole(int $roleId): ?array;

    /**
     * Get role by slug
     *
     * @param string $slug
     * @return array|null
     */
    public function getRoleBySlug(string $slug): ?array;

    /**
     * Get paginated roles
     *
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedRoles(int $perPage = 20): LengthAwarePaginator;

    /**
     * Get all roles
     *
     * @return Collection
     */
    public function getAllRoles(): Collection;

    /**
     * Assign role to user
     *
     * @param int $userId
     * @param int $roleId
     * @return bool
     */
    public function assignRoleToUser(int $userId, int $roleId): bool;

    /**
     * Remove role from user
     *
     * @param int $userId
     * @param int $roleId
     * @return bool
     */
    public function removeRoleFromUser(int $userId, int $roleId): bool;

    /**
     * Get user roles
     *
     * @param int $userId
     * @return Collection
     */
    public function getUserRoles(int $userId): Collection;

    /**
     * Check if user has role
     *
     * @param int $userId
     * @param int $roleId
     * @return bool
     */
    public function userHasRole(int $userId, int $roleId): bool;
}
