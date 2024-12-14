<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    protected function getModel(): Model
    {
        return new User();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->where('email', $email)->first();
    }

    public function findByUsername(string $username): ?User
    {
        return $this->model->where('username', $username)->first();
    }

    public function findWithRoles(int $id): ?User
    {
        return $this->model->with('roles')->find($id);
    }

    public function createWithRoles(array $data, array $roles): User
    {
        $data['password'] = Hash::make($data['password']);
        
        $user = $this->model->create($data);
        $user->roles()->sync($roles);
        
        return $user->load('roles');
    }

    public function updateWithRoles(int $id, array $data, array $roles): bool
    {
        $user = $this->model->findOrFail($id);
        
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }
        
        $updated = $user->update($data);
        $user->roles()->sync($roles);
        
        return $updated;
    }

    public function getUsersWithRoles(): Collection
    {
        return $this->model->with('roles')->get();
    }

    public function paginateWithRoles(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->with('roles')->paginate($perPage);
    }

    public function syncUserRoles(int $userId, array $roleIds): void
    {
        $user = $this->model->findOrFail($userId);
        $user->roles()->sync($roleIds);
    }

    public function isEmailUnique(string $email, ?int $excludeUserId = null): bool
    {
        $query = $this->model->where('email', $email);
        
        if ($excludeUserId) {
            $query->where('id', '!=', $excludeUserId);
        }
        
        return !$query->exists();
    }

    public function isUsernameUnique(string $username, ?int $excludeUserId = null): bool
    {
        $query = $this->model->where('username', $username);
        
        if ($excludeUserId) {
            $query->where('id', '!=', $excludeUserId);
        }
        
        return !$query->exists();
    }
}
