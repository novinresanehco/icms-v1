<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface UserRepositoryInterface
{
    public function find(int $id): ?User;
    public function findByEmail(string $email): ?User;
    public function all(array $filters = []): Collection;
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function create(array $data): User;
    public function update(User $user, array $data): bool;
    public function delete(User $user): bool;
    public function attachRole(User $user, int $roleId): void;
    public function detachRole(User $user, int $roleId): void;
    public function syncRoles(User $user, array $roleIds): void;
    public function attachPermission(User $user, int $permissionId): void;
    public function detachPermission(User $user, int $permissionId): void;
    public function syncPermissions(User $user, array $permissionIds): void;
}
