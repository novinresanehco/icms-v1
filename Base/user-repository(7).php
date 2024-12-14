<?php

namespace App\Repositories;

use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class UserRepository implements UserRepositoryInterface
{
    protected string $table = 'users';

    /**
     * Create a new user
     *
     * @param array $data
     * @return int|null User ID if created, null on failure
     * @throws \InvalidArgumentException If required fields are missing
     */
    public function createUser(array $data): ?int
    {
        $this->validateUserData($data);

        try {
            return DB::table($this->table)->insertGetId(array_merge(
                $data,
                [
                    'password' => Hash::make($data['password']),
                    'created_at' => now(),
                    'updated_at' => now()
                ]
            ));
        } catch (\Exception $e) {
            \Log::error('Failed to create user: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update user information
     *
     * @param int $userId
     * @param array $data
     * @return bool
     */
    public function updateUser(int $userId, array $data): bool
    {
        try {
            $updateData = array_merge($data, ['updated_at' => now()]);
            
            if (isset($updateData['password'])) {
                $updateData['password'] = Hash::make($updateData['password']);
            }

            return DB::table($this->table)
                ->where('id', $userId)
                ->update($updateData) > 0;
        } catch (\Exception $e) {
            \Log::error('Failed to update user: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user by ID
     *
     * @param int $userId
     * @return array|null
     */
    public function getUser(int $userId): ?array
    {
        try {
            $user = DB::table($this->table)
                ->where('id', $userId)
                ->first();

            return $user ? (array) $user : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get user: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get user by email
     *
     * @param string $email
     * @return array|null
     */
    public function getUserByEmail(string $email): ?array
    {
        try {
            $user = DB::table($this->table)
                ->where('email', $email)
                ->first();

            return $user ? (array) $user : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get user by email: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get paginated list of users
     *
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function getPaginatedUsers(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $query = DB::table($this->table);

        // Apply filters
        if (!empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('created_at', 'desc')
                    ->paginate($perPage);
    }

    /**
     * Delete a user
     *
     * @param int $userId
     * @return bool
     */
    public function deleteUser(int $userId): bool
    {
        try {
            return DB::table($this->table)
                ->where('id', $userId)
                ->delete() > 0;
        } catch (\Exception $e) {
            \Log::error('Failed to delete user: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get inactive users
     *
     * @param int $days Days of inactivity
     * @return Collection
     */
    public function getInactiveUsers(int $days): Collection
    {
        return collect(DB::table($this->table)
            ->where('last_login_at', '<', Carbon::now()->subDays($days))
            ->orWhereNull('last_login_at')
            ->get());
    }

    /**
     * Update last login timestamp
     *
     * @param int $userId
     * @return bool
     */
    public function updateLastLogin(int $userId): bool
    {
        try {
            return DB::table($this->table)
                ->where('id', $userId)
                ->update([
                    'last_login_at' => now(),
                    'updated_at' => now()
                ]) > 0;
        } catch (\Exception $e) {
            \Log::error('Failed to update last login: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate user data
     *
     * @param array $data
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function validateUserData(array $data): void
    {
        $required = ['name', 'email', 'password'];
        
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }
    }
}
