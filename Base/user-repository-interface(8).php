<?php

namespace App\Core\Repositories\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface UserRepositoryInterface extends RepositoryInterface
{
    public function findByEmail(string $email): ?User;
    
    public function createUser(array $data): User;
    
    public function updateUser(int $id, array $data): bool;
    
    public function getUsersByRole(string $role): Collection;
    
    public function getActiveUsers(): Collection;
    
    public function updateLastLogin(int $userId): bool;
    
    public function searchUsers(string $query): Collection;
}
