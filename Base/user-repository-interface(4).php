<?php

namespace App\Core\Repositories\Contracts;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface UserRepositoryInterface
{
    public function find(int $id): ?User;
    
    public function findByEmail(string $email): ?User;
    
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;
    
    public function create(array $data): User;
    
    public function update(int $id, array $data): User;
    
    public function delete(int $id): bool;
    
    public function assignRole(int $userId, string|array $roles): bool;
    
    public function removeRole(int $userId, string|array $roles): bool;
    
    public function syncRoles(int $userId, array $roles): bool;
    
    public function getByRole(string $role): Collection;
    
    public function updateProfile(int $userId, array $data): bool;
    
    public function updatePassword(int $userId, string $password): bool;
}
