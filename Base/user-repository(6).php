<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserRepository implements UserRepositoryInterface
{
    protected User $model;
    
    public function __construct(User $model)
    {
        $this->model = $model;
    }

    public function create(array $data): ?int
    {
        try {
            DB::beginTransaction();
            
            $user = $this->model->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'username' => $data['username'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);
            
            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }
            
            DB::commit();
            $this->clearUserCache();
            
            return $user->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create user: ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $userId, array $data): bool
    {
        try {
            DB::beginTransaction();
            
            $user = $this->model->findOrFail($userId);
            
            $updateData = [
                'name' => $data['name'],
                'email' => $data['email'],
                'username' => $data['username'] ?? null,
                'is_active' => $data['is_active'] ?? $user->is_active,
            ];
            
            if (isset($data['password'])) {
                $updateData['password'] = Hash::make($data['password']);
            }
            
            $user->update($updateData);
            
            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }
            
            DB::commit();
            $this->clearUserCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update user: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $userId): bool
    {
        try {
            DB::beginTransaction();
            
            $user = $this->model->findOrFail($userId);
            $user->delete();
            
            DB::commit();
            $this->clearUserCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete user: ' . $e->getMessage());
            return false;
        }
    }

    public function get(int $userId): ?array
    {
        try {
            $user = $this->model->with(['roles', 'permissions'])->find($userId);
            return $user ? $user->toArray() : null;
        } catch (\Exception $e) {
            Log::error('Failed to get user: ' . $e->getMessage());
            return null;
        }
    }

    public function findByEmail(string $email): ?array
    {
        try {
            $user = $this->model->where('email', $email)->first();
            return $user ? $user->toArray() : null;
        } catch (\Exception $e) {
            Log::error('Failed to find user by email: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        try {
            $query = $this->model->query();
            
            if (isset($filters['role'])) {
                $query->role($filters['role']);
            }
            
            if (isset($filters['is_active'])) {
                $query->where('is_active', $filters['is_active']);
            }
            
            if (isset($filters['search'])) {
                $query->where(function($q) use ($filters) {
                    $q->where('name', 'like', "%{$filters['search']}%")
                      ->orWhere('email', 'like', "%{$filters['search']}%")
                      ->orWhere('username', 'like', "%{$filters['search']}%");
                });
            }
            
            return $query->latest()->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get paginated users: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function assignRole(int $userId, string $role): bool
    {
        try {
            $user = $this->model->findOrFail($userId);
            $user->assignRole($role);
            $this->clearUserCache();
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to assign role: ' . $e->getMessage());
            return false;
        }
    }

    public function removeRole(int $userId, string $role): bool
    {
        try {
            $user = $this->model->findOrFail($userId);
            $user->removeRole($role);
            $this->clearUserCache();
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to remove role: ' . $e->getMessage());
            return false;
        }
    }

    public function hasRole(int $userId, string $role): bool
    {
        try {
            $user = $this->model->findOrFail($userId);
            return $user->hasRole($role);
        } catch (\Exception $e) {
            Log::error('Failed to check role: ' . $e->getMessage());
            return false;
        }
    }

    public function syncPermissions(int $userId, array $permissions): bool
    {
        try {
            $user = $this->model->findOrFail($userId);
            $user->syncPermissions($permissions);
            $this->clearUserCache();
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to sync permissions: ' . $e->getMessage());
            return false;
        }
    }

    public function updateProfile(int $userId, array $data): bool
    {
        try {
            DB::beginTransaction();
            
            $user = $this->model->findOrFail($userId);
            $user->update([
                'bio' => $data['bio'] ?? null,
                'profile_photo' => $data['profile_photo'] ?? null,
            ]);
            
            DB::commit();
            $this->clearUserCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update profile: ' . $e->getMessage());
            return false;
        }
    }

    public function updatePassword(int $userId, string $password): bool
    {
        try {
            $user = $this->model->findOrFail($userId);
            $user->update([
                'password' => Hash::make($password)
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update password: ' . $e->getMessage());
            return false;
        }
    }

    public function getByRole(string $role): Collection
    {
        try {
            return $this->model->role($role)->get();
        } catch (\Exception $e) {
            Log::error('Failed to get users by role: ' . $e->getMessage());
            return collect();
        }
    }

    protected function clearUserCache(): void
    {
        Cache::tags(['users'])->flush();
    }
}
