<?php

namespace App\Core\Contracts\Repositories;

use Illuminate\Database\Eloquent\{Model, Collection};

interface UserRepositoryInterface
{
    public function create(array $data): Model;
    public function update(int $id, array $data): Model;
    public function findById(int $id): Model;
    public function findByEmail(string $email): ?Model;
    public function search(array $criteria): Collection;
    public function getByRole(string $role): Collection;
    public function getActiveUsers(): Collection;
    public function updateLastLogin(int $id): void;
    public function delete(int $id): bool;
    public function ban(int $id, ?string $reason = null): void;
    public function updatePermissions(int $id, array $permissions): void;
}
