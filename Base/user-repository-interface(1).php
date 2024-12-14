<?php

namespace App\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface UserRepositoryInterface
{
    public function create(array $data): ?int;
    
    public function update(int $userId, array $data): bool;
    
    public function delete(int $userId): bool;
    
    public function get(int $userId): ?array;
    
    public function findByEmail(string $email): ?array;
    
    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    
    public function assignRole(int $userId, string $role): bool;
    
    public function removeRole(int $userId, string $role): bool;
    
    public function hasRole(int $userId, string $role): bool;
    
    public function syncPermissions(int $userId, array $permissions): bool;
    
    public function updateProfile(int $userId, array $data): bool;
    
    public function updatePassword(int $userId, string $password): bool;
    
    public function getByRole(string $role): Collection;
}
