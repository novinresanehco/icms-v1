<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserRepository implements UserRepositoryInterface
{
    protected User $model;
    protected int $cacheTTL = 3600;

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
                'status' => $data['status'] ?? 'active',
                'role' => $data['role'] ?? 'user',
                'metadata' => $data['metadata'] ?? [],
                'email_verified_at' => $data['email_verified_at'] ?? null,
                'settings' => $data['settings'] ?? [],
                'last_login_at' => null,
                'last_login_ip' => null,
            ]);

            if (!empty($data['permissions'])) {
                $user->permissions()->sync($data['permissions']);
            }

            if (!empty($data['groups'])) {
                $user->groups()->sync($data['groups']);
            }

            $this->clearUserCache();
            DB::commit();

            return $user->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create user: ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            DB::beginTransaction();

            $user = $this->model->findOrFail($id);
            
            $updateData = [
                'name' => $data['name'] ?? $user->name,
                'email' => $data['email'] ?? $user->email,
                'status' => $data['status'] ?? $user->status,
                'role' => $data['role'] ?? $user->role,
                'metadata' => array_merge($user->metadata ?? [], $data['metadata'] ?? []),
                'settings' => array_merge($user->settings ?? [], $data['settings'] ?? []),
            ];

            if (isset($data['password'])) {
                $updateData['password'] = Hash::make($data['password']);
            }

            if (isset($data['email_verified_at'])) {
                $updateData['email_verified_at'] = $data['email_verified_at'];
            }

            $user->update($updateData);

            if (isset($data['permissions'])) {
                $user->permissions()->sync($data['permissions']);
            }

            if (isset($data['groups'])) {
                $user->groups()->sync($data['groups']);
            }

            $this->clearUserCache($id);
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update user: ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $user = $this->model->findOrFail($id);
            
            // Remove from all groups
            $user->groups()->detach();
            
            // Remove permissions
            $user->permissions()->detach();
            
            // Delete or archive user content
            foreach ($user->contents as $content) {
                $content->update(['status' => 'archived']);
            }

            $user->delete();

            $this->clearUserCache($id);
            DB::commit();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete user: ' . $e->getMessage());
            return false;
        }
    }

    public function get(int $id): ?array
    {
        try {
            return Cache::remember(
                "user.{$id}",
                $this->cacheTTL,
                fn() => $this->model->with(['permissions', 'groups'])
                    ->findOrFail($id)
                    ->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get user: ' . $e->getMessage());
            return null;
        }
    }

    public function getByEmail(string $email): ?array
    {
        try {
            return Cache::remember(
                "user.email.{$email}",
                $this->cacheTTL,
                fn() => $this->model->with(['permissions', 'groups'])
                    ->where('email', $email)
                    ->firstOrFail()
                    ->toArray()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get user by email: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        try {
            $query = $this->model->query()
                ->with(['permissions', 'groups']);

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (!empty($filters['role'])) {
                $query->where('role', $filters['role']);
            }

            if (!empty($filters['search'])) {
                $query->where(function ($q) use ($filters) {
                    $q->where('name', 'LIKE', "%{$filters['search']}%")
                        ->orWhere('email', 'LIKE', "%{$filters['search']}%");
                });
            }

            if (!empty($filters['group'])) {
                $query->whereHas('groups', function ($q) use ($filters) {
                    $q->where('id', $filters['group']);
                });
            }

            if (isset($filters['verified'])) {
                if ($filters['verified']) {
                    $query->whereNotNull('email_verified_at');
                } else {
                    $query->whereNull('email_verified_at');
                }
            }

            $orderBy = $filters['order_by'] ?? 'created_at';
            $orderDir = $filters['order_dir'] ?? 'desc';
            $query->orderBy($orderBy, $orderDir);

            return $query->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to get paginated users: ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function updateLoginInfo(int $id, string $ip): bool
    {
        try {
            return $this->model->where('id', $id)->update([
                'last_login_at' => now(),
                'last_login_ip' => $ip
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update user login info: ' . $e->getMessage());
            return false;
        }
    }

    public function getActiveCount(): int
    {
        try {
            return Cache::remember(
                'users.active.count',
                $this->cacheTTL,
                fn() => $this->model->where('status', 'active')->count()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get active users count: ' . $e->getMessage());
            return 0;
        }
    }

    protected function clearUserCache(int $userId = null): void
    {
        if ($userId) {
            Cache::forget("user.{$userId}");
            $user = $this->model->find($userId);
            if ($user) {
                Cache::forget("user.email.{$user->email}");
            }
        }
        
        Cache::tags(['users'])->flush();
    }
}
