<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface RoleRepositoryInterface
{
    public function find(int $id): ?Role;
    public function findByName(string $name): ?Role;
    public function all(array $filters = []): Collection;
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function create(array $data): Role;
    public function update(Role $role, array $data): bool;
    public function delete(Role $role): bool;
    public function attachPermissions(Role $role, array $permissionIds): void;
    public function detachPermissions(Role $role, array $permissionIds): void;
    public function syncPermissions(Role $role, array $permissionIds): void;
    public function getWithPermissions(): Collection;
}
