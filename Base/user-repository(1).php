<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    protected array $searchableFields = ['name', 'email', 'username'];
    protected array $filterableFields = ['status', 'role', 'department_id'];

    /**
     * Get active users with role
     *
     * @param string $role
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getActiveByRole(string $role, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('status', 'active')
            ->whereHas('roles', function($query) use ($role) {
                $query->where('name', $role);
            })
            ->with('roles')
            ->paginate($perPage);
    }

    /**
     * Create user with roles
     *
     * @param array $userData
     * @param array $roles
     * @return User
     */
    public function createWithRoles(array $userData, array $roles): User
    {
        try {
            DB::beginTransaction();

            $userData['password'] = Hash::make($userData['password']);
            $user = $this->create($userData);
            $user->roles()->sync($roles);

            DB::commit();
            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Update user with roles
     *
     * @param int $id
     * @param array $userData
     * @param array $roles
     * @return bool
     */
    public function updateWithRoles(int $id, array $userData, array $roles): bool
    {
        try {
            DB::beginTransaction();

            if (isset($userData['password'])) {
                $userData['password'] = Hash::make($userData['password']);
            }

            $user = $this->find($id);
            if (!$user) {
                return false;
            }

            $this->update($id, $userData);
            $user->roles()->sync($roles);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error updating user with roles: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user activity statistics
     *
     * @param int $userId
     * @return array
     */
    public function getUserStats(int $userId): array
    {
        $cacheKey = "user.stats.{$userId}";

        return Cache::tags(['users'])->remember($cacheKey, 300, function() use ($userId) {
            $user = $this->find($userId);
            if (!$user) {
                return [];
            }

            return [
                'content_count' => $user->content()->count(),
                'published_content' => $user->content()->where('status', 'published')->count(),
                'comments_count' => $user->comments()->count(),
                'last_login' => $user->last_login_at,
                'created_content_last_month' => $user->content()
                    ->where('created_at', '>=', now()->subMonth())
                    ->count()
            ];
        });
    }

    /**
     * Get users by department
     *
     * @param int $departmentId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getByDepartment(int $departmentId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('department_id', $departmentId)
            ->where('status', 'active')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Update user status
     *
     * @param int $id
     * @param string $status
     * @param string|null $reason
     * @return bool
     */
    public function updateStatus(int $id, string $status, ?string $reason = null): bool
    {
        try {
            $updateData = [
                'status' => $status,
                'status_change_reason' => $reason,
                'status_changed_at' => now()
            ];

            if ($status === 'inactive') {
                $updateData['remember_token'] = null;
            }

            return (bool) $this->update($id, $updateData);
        } catch (\Exception $e) {
            \Log::error('Error updating user status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get users with specific permissions
     *
     * @param array $permissions
     * @return Collection
     */
    public function getUsersWithPermissions(array $permissions): Collection
    {
        return $this->model->newQuery()
            ->whereHas('roles.permissions', function($query) use ($permissions) {
                $query->whereIn('name', $permissions);
            })
            ->with(['roles.permissions'])
            ->where('status', 'active')
            ->get();
    }

    /**
     * Search users with advanced filters
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function advancedSearch(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if (!empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query->where(function($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                  ->orWhere('email', 'like', "%{$searchTerm}%")
                  ->orWhere('username', 'like', "%{$searchTerm}%");
            });
        }

        foreach ($this->filterableFields as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (!empty($filters['role'])) {
            $query->whereHas('roles', function($q) use ($filters) {
                $q->where('name', $filters['role']);
            });
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }
}
