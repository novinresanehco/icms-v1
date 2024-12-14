<?php

namespace App\Repositories;

use App\Core\Repositories\BaseRepository;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class UserRepository extends BaseRepository
{
    protected function model(): string
    {
        return User::class;
    }

    public function findByEmail(string $email): ?User
    {
        return $this->newQuery()
            ->where('email', $email)
            ->first();
    }

    public function findActiveUsers(): Collection
    {
        return $this->newQuery()
            ->where('status', 'active')
            ->orderBy('last_login_at', 'desc')
            ->get();
    }

    public function findByRole(string $role): Collection
    {
        return $this->newQuery()
            ->whereHas('roles', function($query) use ($role) {
                $query->where('name', $role);
            })
            ->get();
    }

    public function updateLastLogin(User $user): bool
    {
        return $user->update([
            'last_login_at' => Carbon::now(),
            'login_count' => $user->login_count + 1
        ]);
    }

    public function findInactiveUsers(int $days = 30): Collection
    {
        return $this->newQuery()
            ->where('last_login_at', '<=', Carbon::now()->subDays($days))
            ->orWhereNull('last_login_at')
            ->get();
    }

    public function searchUsers(string $query): Collection
    {
        return $this->newQuery()
            ->where(function($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function countByStatus(string $status): int
    {
        return $this->newQuery()
            ->where('status', $status)
            ->count();
    }
}
