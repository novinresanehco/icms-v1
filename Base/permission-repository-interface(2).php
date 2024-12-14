<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Permission;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface PermissionRepositoryInterface
{
    public function find(int $id): ?Permission;
    public function findByName(string $name, string $guardName = 'web'): ?Permission;
    public function all(array $filters = []): Collection;
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    public function create(array $data): Permission;
    public function update(Permission $permission, array $data): bool;
    public function delete(Permission $permission): bool;
    public function getByGuard(string $guardName): Collection;
    public function getByModule(string $module): Collection;
    public function syncPermissions(array $permissions): void;
}
