<?php

namespace App\Core\Auth\Repository;

use App\Core\Auth\Models\User;
use App\Core\Auth\DTO\UserData;
use App\Core\Shared\Repository\RepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface AuthRepositoryInterface extends RepositoryInterface
{
    /**
     * Find user by email.
     *
     * @param string $email
     * @return User|null
     */
    public function findByEmail(string $email): ?User;

    /**
     * Find user by username.
     *
     * @param string $username
     * @return User|null
     */
    public function findByUsername(string $username): ?User;

    /**
     * Create new user.
     *
     * @param UserData $data
     * @return User
     */
    public function createUser(UserData $data): User;

    /**
     * Update user.
     *
     * @param int $id
     * @param UserData $data
     * @return User
     */
    public function updateUser(int $id, UserData $data): User;

    /**
     * Update user's password.
     *
     * @param int $id
     * @param string $password
     * @return bool
     */
    public function updatePassword(int $id, string $password): bool;

    /**
     * Get users by role.
     *
     * @param string $role
     * @return Collection
     */
    public function getUsersByRole(string $role): Collection;

    /**
     * Get paginated users.
     *
     * @param int $page
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function paginateUsers(int $page = 1, int $perPage = 15, array $filters = []): LengthAwarePaginator;

    /**
     * Assign roles to user.
     *
     * @param int $userId
     * @param array $roles
     * @return User
     */
    public function assignRoles(int $userId, array $roles): User;

    /**
     * Get user's permissions.
     *
     * @param int $userId
     * @return array
     */
    public function getUserPermissions(int $userId): array;

    /**
     * Check if user has permission.
     *
     * @param int $userId
     * @param string $permission
     * @return bool
     */
    public function hasPermission(int $userId, string $permission): bool;

    /**
     * Get user's activity log.
     *
     * @param int $userId
     * @param array $options
     * @return Collection
     */
    public function getUserActivityLog(int $userId, array $options = []): Collection;

    /**
     * Ban user.
     *
     * @param int $userId
     * @param string|null $reason
     * @param \DateTime|null $until
     * @return bool
     */
    public function banUser(int $userId, ?string $reason = null, ?\DateTime $until = null): bool;

    /**
     * Unban user.
     *
     * @param int $userId
     * @return bool
     */
    public function unbanUser(int $userId): bool;
}
