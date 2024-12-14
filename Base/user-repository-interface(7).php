<?php

namespace App\Repositories\Contracts;

interface UserRepositoryInterface
{
    public function find(int $id);
    public function findByEmail(string $email);
    public function getAll(array $filters = []);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
    public function getAuthors();
    public function updateProfile(int $userId, array $data);
    public function updatePassword(int $userId, string $password);
    public function assignRole(int $userId, string $role);
    public function removeRole(int $userId, string $role);
    public function syncRoles(int $userId, array $roles);
    public function getByRole(string $role);
}
