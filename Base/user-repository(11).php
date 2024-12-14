<?php

namespace App\Core\Repositories;

use App\Core\Models\User;
use App\Core\Repositories\Contracts\UserRepositoryInterface;
use App\Core\Exceptions\UserException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{Cache, DB, Hash, Log};

class UserRepository implements UserRepositoryInterface
{
    protected User $model;
    protected const CACHE_TTL = 3600;

    public function __construct(User $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?User
    {
        return Cache::remember("users.{$id}", self::CACHE_TTL, function () use ($id) {
            return $this->model->with(['roles', 'permissions', 'profile'])
                             ->find($id);
        });
    }

    public function findByEmail(string $email): ?User
    {
        return Cache::remember("users.email.{$email}", self::CACHE_TTL, function () use ($email) {
            return $this->model->with(['roles', 'permissions', 'profile'])
                             ->where('email', $email)
                             ->first();
        });
    }

    public function all(array $filters = []): Collection
    {
        $query = $this->model->with(['roles', 'permissions', 'profile']);
        return $this->applyFilters($query, $filters)->get();
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with(['roles', 'permissions', 'profile']);
        return $this->applyFilters($query, $filters)->paginate($perPage);
    }

    public function create(array $data): User
    {
        try {
            DB::beginTransaction();

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $user = $this->model->create($data);

            if (isset($data['roles'])) {
                $user->roles()->attach($data['roles']);
            }

            if (isset($data['permissions'])) {
                $user->permissions()->attach($data['permissions']);
            }

            if (isset($data['profile'])) {
                $user->profile()->create($data['profile']);
            }

            DB::commit();
            $this->clearCache();

            return $user->fresh(['roles', 'permissions', 'profile']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User creation failed:', ['error' => $e->getMessage(), 'data' => $data]);
            throw new UserException('Failed to create user: ' . $e->getMessage());
        }
    }

    public function update(User $user, array $data): bool
    {
        try {
            DB::beginTransaction();

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $user->update($data);

            if (isset($data['roles'])) {
                $user->roles()->sync($data['roles']);
            }

            if (isset($data['permissions'])) {
                $user->permissions()->sync($data['permissions']);
            }

            if (isset($data['profile'])) {
                $user->profile()->update($data['profile']);
            }

            DB::commit();
            $this->clearCache($user->id);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User update failed:', ['id' => $user->id, 'error' => $e->getMessage()]);
            throw new UserException('Failed to update user: ' . $e->getMessage());
        }
    }

    public function delete(User $user): bool
    {
        try {
            DB::beginTransaction();

            $user->profile()->delete();
            $user->roles()->detach();
            $user->permissions()->detach();
            $user->delete();

            DB::commit();
            $this->clearCache($user->id);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User deletion failed:', ['id' => $user->id, 'error' => $e->getMessage()]);
            throw new UserException('Failed to delete user: ' . $e->getMessage());
        }
    }

    public function attachRole(User $user, int $roleId): void
    {
        $user->roles()->attach($roleId);
        $this->clearCache($user->id);
    }

    public function detachRole(User $user, int $roleId): void
    {
        $user->roles()->detach($roleId);
        $this->clearCache($user->id);
    }

    public function syncRoles(User $user, array $roleIds): void
    {
        $user->roles()->sync($roleIds);
        $this->clearCache($user->id);
    }

    public function attachPermission(User $user, int $permissionId): void
    {
        $user->permissions()->attach($permissionId);
        $this->clearCache($user->id);
    }

    public function detachPermission(User $user, int $permissionId): void
    {
        $user->permissions()->detach($permissionId);
        $this->clearCache($user->id);
    }

    public function syncPermissions(User $user, array $permissionIds): void
    {
        $user->permissions()->sync($permissionIds);
        $this->clearCache($user->id);
    }

    protected function applyFilters($query, array $filters): object
    {
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('email', 'like', "%{$filters['search']}%");
            });
        }

        if (!empty($filters['role_id'])) {
            $query->whereHas('roles', function ($q) use ($filters) {
                $q->where('roles.id', $filters['role_id']);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }

        if (!empty($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';
        $query->orderBy($sort, $direction);

        return $query;
    }

    protected function clearCache(?int $userId = null): void
    {
        if ($userId) {
            Cache::forget("users.{$userId}");
            $user = $this->model->find($userId);
            if ($user) {
                Cache::forget("users.email.{$user->email}");
            }
        }
        
        Cache::tags(['users'])->flush();
    }
}
