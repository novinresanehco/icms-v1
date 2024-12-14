<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    protected array $searchableFields = ['name', 'email', 'username'];
    protected array $filterableFields = ['role', 'status', 'department'];

    public function create(array $data): User
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return parent::create($data);
    }

    public function update(int $id, array $data): bool
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return parent::update($id, $data);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model
            ->where('email', $email)
            ->first();
    }

    public function getActiveWithRoles(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with('roles')
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getUsersByRole(string $role): Collection
    {
        return $this->model
            ->whereHas('roles', function($query) use ($role) {
                $query->where('name', $role);
            })
            ->get();
    }

    public function updateLastLogin(int $userId): bool
    {
        return $this->update($userId, [
            'last_login_at' => now(),
            'last_login_ip' => request()->ip()
        ]);
    }

    public function getInactiveUsers(int $days = 30): Collection
    {
        return $this->model
            ->where('last_login_at', '<', now()->subDays($days))
            ->orWhereNull('last_login_at')
            ->get();
    }
}
