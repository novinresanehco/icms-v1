<?php

namespace App\Core\Repositories;

use App\Models\User;
use Illuminate\Support\Collection;
use App\Core\Services\Cache\CacheService;

class UserRepository extends AdvancedRepository
{
    protected $model = User::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function findByEmail(string $email): ?User
    {
        return $this->executeQuery(function() use ($email) {
            return $this->model->where('email', $email)->first();
        });
    }

    public function getActiveUsers(): Collection
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('users.active', function() {
                return $this->model
                    ->where('status', 'active')
                    ->orderBy('last_login_at', 'desc')
                    ->get();
            });
        });
    }

    public function updateLastLogin(User $user): void
    {
        $this->executeTransaction(function() use ($user) {
            $user->update([
                'last_login_at' => now(),
                'last_login_ip' => request()->ip()
            ]);
            $this->cache->forget("user.{$user->id}");
        });
    }

    public function attachRole(User $user, int $roleId): void
    {
        $this->executeTransaction(function() use ($user, $roleId) {
            $user->roles()->attach($roleId);
            $this->cache->forget("user.roles.{$user->id}");
        });
    }
}
