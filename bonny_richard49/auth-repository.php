<?php

namespace App\Core\Auth\Repository;

use App\Core\Auth\Models\User;
use App\Core\Auth\DTO\UserData;
use App\Core\Auth\Events\UserCreated;
use App\Core\Auth\Events\UserUpdated;
use App\Core\Auth\Events\UserBanned;
use App\Core\Auth\Events\UserUnbanned;
use App\Core\Auth\Exceptions\UserNotFoundException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Event;

class AuthRepository extends BaseRepository implements AuthRepositoryInterface
{
    protected const CACHE_KEY = 'users';
    protected const CACHE_TTL = 3600; // 1 hour

    public function __construct(CacheManagerInterface $cache)
    {
        parent::__construct($cache);
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return User::class;
    }

    public function findByEmail(string $email): ?User
    {
        return $this->cache->remember(
            $this->getCacheKey("email:{$email}"),
            fn() => $this->model->where('email', $email)->first()
        );
    }

    public function findByUsername(string $username): ?User
    {
        return $this->cache->remember(
            $this->getCacheKey("username:{$username}"),
            fn() => $this->model->where('username', $username)->first()
        );
    }

    public function createUser(UserData $data): User
    {
        DB::beginTransaction();
        try {
            // Create user
            $user = $this->model->create([
                'name' => $data->name,
                'email' => $data->email,
                'username' => $data->username,
                'password' => Hash::make($data->password),
                'settings' => $data->settings ?? [],
                'meta' => $data->meta ?? []
            ]);

            // Assign roles
            if (!empty($data->roles)) {
                $user->roles()->sync($data->roles);
            }

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new UserCreated($user));

            DB::commit();
            return $user->fresh(['roles', 'permissions']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateUser(int $id, UserData $data): User
    {
        DB::beginTransaction();
        try {
            $user = $this->findOrFail($id);

            // Update user
            $updateData = [
                'name' => $data->name,
                'email' => $data->email,
                'username' => $data->username,
                'settings' => array_merge($user->settings ?? [], $data->settings ?? []),
                'meta' => array_merge($user->meta ?? [], $data->meta ?? [])
            ];

            if ($data->password) {
                $updateData['password'] = Hash::make($data->password);
            }

            $user->update($updateData);

            // Update roles if provided
            if (isset($data->roles)) {
                $user->roles()->sync($data->roles);
            }

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new UserUpdated($user));

            DB::commit();
            return $user->fresh(['roles', 'permissions']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updatePassword(int $id, string $password): bool
    {
        DB::beginTransaction();
        try {
            $user = $this->findOrFail($id);
            
            $user->update([
                'password' => Hash::make($password)
            ]);

            // Clear cache
            $this->clearCache();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getUsersByRole(string $role): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("role:{$role}"),
            fn() => $this->model->whereHas('roles', function($query) use ($role) {
                $query->where('name', $role);
            })->get()
        );
    }

    public function paginateUsers(int $page = 1, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        // Apply filters
        if (!empty($filters['role'])) {
            $query->whereHas('roles', function($q) use ($filters) {
                $q->where('name', $filters['role']);
            });
        }

        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->where('name', 'LIKE', "%{$filters['search']}%")
                  ->orWhere('email', 'LIKE', "%{$filters['search']}%")
                  ->orWhere('username', 'LIKE', "%{$filters['search']}%");
            });
        }

        if (isset($filters['active'])) {
            $query->where('is_active', $filters['active']);
        }

        return $query->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
                    ->paginate($perPage, ['*'], 'page', $page);
    }

    public function assignRoles(int $userId, array $roles): User
    {
        DB::beginTransaction();
        try {
            $user = $this->findOrFail($userId);
            
            $user->roles()->sync($roles);
            
            // Clear cache
            $this->clearCache();

            DB::commit();
            return $user->fresh(['roles', 'permissions']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getUserPermissions(int $userId): array
    {
        return $this->cache->remember(
            $this->getCacheKey("permissions:{$userId}"),
            function() use ($userId) {
                $user = $this->findOrFail($userId);
                
                return $user->getAllPermissions()
                           ->pluck('name')
                           ->toArray();
            }
        );
    }

    public function hasPermission(int $userId, string $permission): bool
    {
        $permissions = $this->getUserPermissions($userId);
        return in_array($permission, $permissions);
    }

    public function getUserActivityLog(int $userId, array $options = []): Collection
    {
        $query = $this->model->findOrFail($userId)
                            ->activities()
                            ->with('causer');

        if (!empty($options['type'])) {
            $query->where('type', $options['type']);
        }

        return $query->orderBy('created_at', 'desc')
                    ->limit($options['limit'] ?? 50)
                    ->get();
    }

    public function banUser(int $userId, ?string $reason = null, ?\DateTime $until = null): bool
    {
        DB::beginTransaction();
        try {
            $user = $this->findOrFail($userId);
            
            $user->update([
                'is_banned' => true,
                'banned_reason' => $reason,
                'banned_until' => $until,
            ]);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new UserBanned($user));

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function unbanUser(int $userId): bool
    {
        DB::beginTransaction();
        try {
            $user = $this->findOrFail($userId);
            
            $user->update([
                'is_banned' => false,
                'banned_reason' => null,
                'banned_until' => null,
            ]);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new UserUnbanned($user));

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
