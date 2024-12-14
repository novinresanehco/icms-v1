<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UserRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Get active users with role
     *
     * @param string $role
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getActiveByRole(string $role, int $perPage = 15): LengthAwarePaginator;

    /**
     * Create user with roles
     *
     * @param array $userData
     * @param array $roles
     * @return User
     */
    public function createWithRoles(array $userData, array $roles): User;

    /**
     * Update user with roles
     *
     * @param int $id
     * @param array $userData
     * @param array $roles
     * @return bool
     */
    public function updateWithRoles(int $id, array $userData, array $roles): bool;

    /**
     * Get user activity statistics
     *
     * @param int $userId
     * @return array
     */
    public function getUserStats(int $userId): array;

    /**
     * Get users by department
     *
     * @param int $departmentId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getByDepartment(int $departmentId, int $perPage = 15): LengthAwarePaginator;

    /**
     * Update user status
     *
     * @param int $id
     * @param string $status
     * @param string|null $reason
     * @return bool
     */
    public function updateStatus(int $id, string $status, ?string $reason = null): bool;

    /**
     * Get users with specific permissions
     *
     * @param array $permissions
     * @return Collection
     */
    public function getUsersWithPermissions(array $permissions): Collection;

    /**
     * Search users with advanced filters
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function advancedSearch(array $filters, int $perPage = 15): LengthAwarePaginator;
}
