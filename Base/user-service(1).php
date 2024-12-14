<?php

namespace App\Core\Services;

use App\Core\Models\User;
use App\Core\Services\Contracts\UserServiceInterface;
use App\Core\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

class UserService implements UserServiceInterface
{
    public function __construct(
        private UserRepositoryInterface $repository
    ) {}

    public function getUser(int $id): ?User
    {
        return Cache::tags(['users'])->remember(
            "users.{$id}",
            now()->addHour(),
            fn() => $this->repository->findById($id)
        );
    }

    public function getUserByEmail(string $email): ?User
    {
        return Cache::tags(['users'])->remember(
            "users.email.{$email}",
            now()->addHour(),
            fn() => $this->repository->findByEmail($email)
        );
    }

    public function getAllUsers(int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getAllPaginated($perPage);
    }

    public function getUsersByRole(string $role, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getByRole($role, $perPage);
    }

    public function getAdminUsers(): Collection
    {
        return Cache::tags(['users'])->remember(
            'users.admins',
            now()->addHour(),
            fn() => $this->repository->getAdmins()
        );
    }

    public function getActiveUsers(): Collection
    {
        return Cache::tags(['users'])->remember(
            'users.active',
            now()->addHour(),
            fn() => $this->repository->getActiveUsers()
        );
    }

    public function createUser(array $data): User
    {
        $user = $this->repository->store($data);
        Cache::tags(['users'])->flush();
        return $user;
    }

    public function updateUser(int $id, array $data): User
    {
        $user = $this->repository->update($id, $data);
        Cache::tags(['users'])->flush();
        return $user;
    }

    public function deleteUser(int $id): bool
    {
        $result = $this->repository->delete($id);
        Cache::tags(['users'])->flush();
        return $result;
    }

    public function restoreUser(int $id): bool
    {
        $result = $this->repository->restore($id);
        Cache::tags(['users'])->flush();
        return $result;
    }

    public function updateUserLastLogin(int $id): bool
    {
        $result = $this->repository->updateLastLogin($id);
        Cache::tags(['users'])->flush();
        return $result;
    }

    public function assignUserRole(int $id, string $role): bool
    {
        $result = $this->repository->assignRole($id, $role);
        Cache::tags(['users'])->flush();
        return $result;
    }

    public function removeUserRole(int $id, string $role): bool
    {
        $result = $this->repository->removeRole($id, $role);
        Cache::tags(['users'])->flush();
        return $result;
    }
}
