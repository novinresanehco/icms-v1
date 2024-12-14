<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

interface RoleRepositoryInterface extends RepositoryInterface
{
    public function getAllWithPermissions(): Collection;
    
    public function syncPermissions(int $roleId, array $permissions): bool;
    
    public function createRole(string $name, array $permissions = []): Role;
    
    public function findBySlug(string $slug): ?Role;
    
    public function getRoleUsers(int $roleId): Collection;
}
