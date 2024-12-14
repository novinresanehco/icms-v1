<?php

namespace App\Core\Services\Contracts;

use App\Core\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface UserServiceInterface
{
    public function getUser(int $id): ?User;
    
    public function getUserByEmail(string $email): ?User;
    
    public function getAllUsers(int $perPage = 15): LengthAwarePaginator;
    
    public function getUsersByRole(string $role, int $perPage = 15): LengthAwarePaginator;
    
    public function getAdminUsers(): Collection;
    
    public function getActiveUsers(): Collection;
    
    public function createUser(array $data): User;
    
    public function updateUser(int $id, array $data): User;
    
    public function deleteUser(int $id): bool;
    
    public function restoreUser(int $id): bool;
    
    public function updateUserLastLogin(int $id): bool;
    
    public function assignUserRole(int $id, string $role): bool;
    
    public function removeUserRole(int $id, string $role): bool;
}
