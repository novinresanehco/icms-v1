<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->where('email', $email)->first();
    }

    public function createUser(array $data): User
    {
        $data['password'] = Hash::make($data['password']);
        
        $user = $this->create($data);
        
        if (isset($data['roles'])) {
            $user->roles()->sync($data['roles']);
        }
        
        return $user;
    }

    public function updateUser(int $id, array $data): bool
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $success = $this->update($id, $data);

        if ($success && isset($data['roles'])) {
            $user = $this->find($id);
            $user->roles()->sync($data['roles']);
        }

        return $success;
    }

    public function getUsersByRole(string $role): Collection
    {
        return Cache::tags(['users', "role:{$role}"])->remember(
            "users:role:{$role}",
            now()->addHours(6),
            fn () => $this->model
                ->whereHas('roles', function ($query) use ($role) {
                    $query->where('name', $role);
                })
                ->get()
        );
    }

    public function getActiveUsers(): Collection
    {
        return Cache::tags(['users', 'active'])->remember(
            'users:active',
            now()->addMinutes(30),
            fn () => $this->model
                ->where('status', 'active')
                ->orderBy('last_login_at', 'desc')
                ->get()
        );
    }

    public function updateLastLogin(int $userId): bool
    {
        return $this->update($userId, [
            'last_login_at' => now(),
            'login_count' => $this->model->raw('login_count + 1')
        ]);
    }

    public function searchUsers(string $query): Collection
    {
        return $this->model
            ->where('name', 'LIKE', "%{$query}%")
            ->orWhere('email', 'LIKE', "%{$query}%")
            ->orderBy('name')
            ->get();
    }
}
