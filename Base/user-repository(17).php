<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserRepository implements UserRepositoryInterface
{
    protected $model;

    public function __construct(User $model)
    {
        $this->model = $model;
    }

    public function find(int $id)
    {
        return $this->model->with(['roles', 'permissions'])->findOrFail($id);
    }

    public function findByEmail(string $email)
    {
        return $this->model->with(['roles', 'permissions'])
            ->where('email', $email)
            ->firstOrFail();
    }

    public function getAll(array $filters = []): Collection
    {
        return $this->model->with(['roles', 'permissions'])
            ->when(isset($filters['search']), function ($query) use ($filters) {
                return $query->where(function ($q) use ($filters) {
                    $q->where('name', 'like', "%{$filters['search']}%")
                      ->orWhere('email', 'like', "%{$filters['search']}%");
                });
            })
            ->when(isset($filters['role']), function ($query) use ($filters) {
                return $query->whereHas('roles', function ($q) use ($filters) {
                    $q->where('name', $filters['role']);
                });
            })
            ->when(isset($filters['active']), function ($query) use ($filters) {
                return $query->where('is_active', $filters['active']);
            })
            ->orderBy('name')
            ->get();
    }

    public function create(array $data)
    {
        return DB::transaction(function () use ($data) {
            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $user = $this->model->create($data);

            if (isset($data['roles'])) {
                $user->roles()->sync($data['roles']);
            }

            return $user->fresh(['roles', 'permissions']);
        });
    }

    public function update(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $user = $this->find($id);

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $user->update($data);

            if (isset($data['roles'])) {
                $user->roles()->sync($data['roles']);
            }

            return $user->fresh(['roles', 'permissions']);
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $user = $this->find($id);
            
            // Remove all roles
            $user->roles()->detach();
            
            // Remove all direct permissions
            $user->permissions()->detach();
            
            return $user->delete();
        });
    }

    public function getAuthors(): Collection
    {
        return $this->model->whereHas('roles', function ($query) {
                $query->where('name', 'author');
            })
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function updateProfile(int $userId, array $data)
    {
        return DB::transaction(function () use ($userId, $data) {
            $user = $this->find($userId);
            
            // Handle avatar upload if present
            if (isset($data['avatar'])) {
                if ($user->avatar) {
                    Storage::disk('public')->delete($user->avatar);
                }
                $data['avatar'] = $data['avatar']->store('avatars', 'public');
            }
            
            $user->update($data);
            return $user->fresh();
        });
    }

    public function updatePassword(int $userId, string $password)
    {
        return DB::transaction(function () use ($userId, $password) {
            $user = $this->find($userId);
            $user->update([
                'password' => Hash::make($password)
            ]);
            return $user;
        });
    }

    public function assignRole(int $userId, string $role)
    {
        $user = $this->find($userId);
        $user->assignRole($role);
        return $user->fresh(['roles', 'permissions']);
    }

    public function removeRole(int $userId, string $role)
    {
        $user = $this->find($userId);
        $user->removeRole($role);
        return $user->fresh(['roles', 'permissions']);
    }

    public function syncRoles(int $userId, array $roles)
    {
        $user = $this->find($userId);
        $user->syncRoles($roles);
        return $user->fresh(['roles', 'permissions']);
    }

    public function getByRole(string $role): Collection
    {
        return $this->model->role($role)
            ->with(['roles', 'permissions'])
            ->orderBy('name')
            ->get();
    }
}
