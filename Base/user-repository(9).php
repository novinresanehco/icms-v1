<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserRepository implements UserRepositoryInterface
{
    protected User $model;

    public function __construct(User $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?User
    {
        return $this->model->with(['roles', 'permissions'])->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->where('email', $email)->first();
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with(['roles']);

        if (!empty($filters['role'])) {
            $query->whereHas('roles', function($q) use ($filters) {
                $q->where('name', $filters['role']);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->where('name', 'LIKE', "%{$filters['search']}%")
                  ->orWhere('email', 'LIKE', "%{$filters['search']}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    public function create(array $data): User
    {
        DB::beginTransaction();
        try {
            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $user = $this->model->create($data);

            if (!empty($data['roles'])) {
                $user->assignRole($data['roles']);
            }

            if (!empty($data['meta'])) {
                $user->meta()->createMany($data['meta']);
            }

            DB::commit();
            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): User
    {
        DB::beginTransaction();
        try {
            $user = $this->model->findOrFail($id);

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $user->update($data);

            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }

            if (isset($data['meta'])) {
                $user->meta()->delete();
                $user->meta()->createMany($data['meta']);
            }

            DB::commit();
            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $user = $this->model->findOrFail($id);
            $user->roles()->detach();
            $user->permissions()->detach();
            $user->meta()->delete();
            $user->delete();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function assignRole(int $userId, string|array $roles): bool
    {
        try {
            $user = $this->model->findOrFail($userId);
            $user->assignRole($roles);
            return true;
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    public function removeRole(int $userId, string|array $roles): bool
    {
        try {
            $user = $this->model->findOrFail($userId);
            $user->removeRole($roles);
            return true;
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    public function syncRoles(int $userId, array $roles): bool
    {
        try {
            $user = $this->model->findOrFail($userId);
            $user->syncRoles($roles);
            return true;
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    public function getByRole(string $role): Collection
    {
        return $this->model->role($role)->get();
    }

    public function updateProfile(int $userId, array $data): bool
    {
        DB::beginTransaction();
        try {
            $user = $this->model->findOrFail($userId);
            
            $user->update([
                'name' => $data['name'] ?? $user->name,
                'email' => $data['email'] ?? $user->email,
            ]);

            if (!empty($data['meta'])) {
                foreach ($data['meta'] as $key => $value) {
                    $user->meta()->updateOrCreate(
                        ['key' => $key],
                        ['value' => $value]
                    );
                }
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);
            return false;
        }
    }

    public function updatePassword(int $userId, string $password): bool
    {
        try {
            return $this->model->findOrFail($userId)
                ->update(['password' => Hash::make($password)]);
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }
}
