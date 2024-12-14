<?php

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\UserRepositoryInterface;
use App\Core\Models\User;
use App\Core\Exceptions\UserRepositoryException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{Cache, DB, Hash};
use Illuminate\Support\Carbon;

class UserRepository implements UserRepositoryInterface
{
    protected User $model;
    protected const CACHE_PREFIX = 'user:';
    protected const CACHE_TTL = 3600;

    public function __construct(User $model)
    {
        $this->model = $model;
    }

    public function create(array $data): Model
    {
        try {
            DB::beginTransaction();

            $user = $this->model->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => $data['role'] ?? 'user',
                'status' => $data['status'] ?? 'active',
                'preferences' => $data['preferences'] ?? [],
                'email_verified_at' => $data['email_verified'] ?? null
            ]);

            if (!empty($data['permissions'])) {
                $user->permissions()->sync($data['permissions']);
            }

            if (!empty($data['profile'])) {
                $user->profile()->create($data['profile']);
            }

            DB::commit();
            $this->clearCache($user->id);

            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new UserRepositoryException("Failed to create user: {$e->getMessage()}", 0, $e);
        }
    }

    public function update(int $id, array $data): Model
    {
        try {
            DB::beginTransaction();

            $user = $this->findById($id);
            
            $updateData = [
                'name' => $data['name'] ?? $user->name,
                'email' => $data['email'] ?? $user->email,
                'role' => $data['role'] ?? $user->role,
                'status' => $data['status'] ?? $user->status,
                'preferences' => array_merge($user->preferences ?? [], $data['preferences'] ?? [])
            ];

            if (isset($data['password'])) {
                $updateData['password'] = Hash::make($data['password']);
            }

            $user->update($updateData);

            if (isset($data['permissions'])) {
                $user->permissions()->sync($data['permissions']);
            }

            if (isset($data['profile'])) {
                $user->profile()->updateOrCreate(
                    ['user_id' => $user->id],
                    $data['profile']
                );
            }

            DB::commit();
            $this->clearCache($id);

            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new UserRepositoryException("Failed to update user: {$e->getMessage()}", 0, $e);
        }
    }

    public function findById(int $id): Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->with(['profile', 'permissions'])->findOrFail($id)
        );
    }

    public function findByEmail(string $email): ?Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . "email:{$email}",
            self::CACHE_TTL,
            fn () => $this->model->with(['profile', 'permissions'])
                ->where('email', $email)
                ->first()
        );
    }

    public function search(array $criteria): Collection
    {
        $query = $this->model->with(['profile', 'permissions']);

        if (isset($criteria['term'])) {
            $query->where(function ($q) use ($criteria) {
                $q->where('name', 'like', "%{$criteria['term']}%")
                  ->orWhere('email', 'like', "%{$criteria['term']}%");
            });
        }

        if (isset($criteria['role'])) {
            $query->where('role', $criteria['role']);
        }

        if (isset($criteria['status'])) {
            $query->where('status', $criteria['status']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($criteria['per_page'] ?? 15);
    }

    public function getByRole(string $role): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . "role:{$role}",
            self::CACHE_TTL,
            fn () => $this->model->where('role', $role)
                ->with(['profile', 'permissions'])
                ->get()
        );
    }

    public function getActiveUsers(): Collection
    {
        return Cache::remember(
            self::CACHE_PREFIX . 'active',
            self::CACHE_TTL,
            fn () => $this->model->where('status', 'active')
                ->with(['profile', 'permissions'])
                ->get()
        );
    }

    public function updateLastLogin(int $id): void
    {
        try {
            $user = $this->findById($id);
            $user->update(['last_login_at' => Carbon::now()]);
            $this->clearCache($id);
        } catch (\Exception $e) {
            throw new UserRepositoryException("Failed to update last login: {$e->getMessage()}", 0, $e);
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $user = $this->findById($id);
            $user->permissions()->detach();
            $user->profile()->delete();
            $deleted = $user->delete();

            DB::commit();
            $this->clearCache($id);

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new UserRepositoryException("Failed to delete user: {$e->getMessage()}", 0, $e);
        }
    }

    public function ban(int $id, ?string $reason = null): void
    {
        try {
            DB::beginTransaction();

            $user = $this->findById($id);
            $user->update([
                'status' => 'banned',
                'banned_at' => Carbon::now(),
                'ban_reason' => $reason
            ]);

            DB::commit();
            $this->clearCache($id);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new UserRepositoryException("Failed to ban user: {$e->getMessage()}", 0, $e);
        }
    }

    public function updatePermissions(int $id, array $permissions): void
    {
        try {
            DB::beginTransaction();

            $user = $this->findById($id);
            $user->permissions()->sync($permissions);

            DB::commit();
            $this->clearCache($id);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new UserRepositoryException("Failed to update permissions: {$e->getMessage()}", 0, $e);
        }
    }

    protected function clearCache(int $id): void
    {
        $user = $this->model->find($id);
        if ($user) {
            Cache::forget(self::CACHE_PREFIX . $id);
            Cache::forget(self::CACHE_PREFIX . "email:{$user->email}");
            Cache::tags(['users'])->flush();
        }
    }
}
