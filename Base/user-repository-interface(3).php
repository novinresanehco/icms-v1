<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
    public function findByUsername(string $username): ?User;
    public function findWithRoles(int $id): ?User;
    public function createWithRoles(array $data, array $roles): User;
    public function updateWithRoles(int $id, array $data, array $roles): bool;
    public function getUsersWithRoles(): Collection;
    public function paginateWithRoles(int $perPage = 15): LengthAwarePaginator;
    public function syncUserRoles(int $userId, array $roleIds): void;
    public function isEmailUnique(string $email, ?int $excludeUserId = null): bool;
    public function isUsernameUnique(string $username, ?int $excludeUserId = null): bool;
}
