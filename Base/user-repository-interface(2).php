<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface UserRepositoryInterface
{
    /**
     * Create a new user
     *
     * @param array $data
     * @return int|null
     */
    public function createUser(array $data): ?int;

    /**
     * Update user information
     *
     * @param int $userId
     * @param array $data
     * @return bool
     */
    public function updateUser(int $userId, array $data): bool;

    /**
     * Get user by ID
     *
     * @param int $userId
     * @return array|null
     */
    public function getUser(int $userId): ?array;

    /**
     * Get user by email
     *
     * @param string $email
     * @return array|null
     */
    public function getUserByEmail(string $email): ?array;

    /**
     * Get paginated list of users
     *
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getPaginatedUsers(int $perPage = 20, array $filters = []): LengthAwarePaginator;

    /**
     * Delete a user
     *
     * @param int $userId
     * @return bool
     */
    public function deleteUser(int $userId): bool;

    /**
     * Get inactive users
     *
     * @param int $days
     * @return Collection
     */
    public function getInactiveUsers(int $days): Collection;

    /**
     * Update last login timestamp
     *
     * @param int $userId
     * @return bool
     */
    public function updateLastLogin(int $userId): bool;
}
