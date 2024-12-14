<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface UserRepositoryInterface
{
    public function findById(int $id): ?User;
    
    public function findByEmail(string $email): ?User;
    
    public function getAllPaginated(int $perPage = 15): LengthAwarePaginator;
    
    public function getByRole(string $role, int $perPage = 15): LengthAwarePaginator;
    
    public function getAdmins(): Collection;
    
    public function getActiveUsers(): Collection;
    
    public function store(array $data): User;
    
    public function update(int $id, array $data): User;
    
    public function delete(int $id): bool;
    
    public function restore(int $id): bool;
    
    public function updateLastLogin(int $id): bool;
    
    public function assignRole(int $id, string $role): bool;
    
    public function removeRole(int $id, string $role): bool;
}
