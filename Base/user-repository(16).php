<?php

namespace App\Repositories;

use App\Models\User;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class UserRepository extends BaseRepository
{
    public function __construct(User $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findByEmail(string $email): ?User
    {
        return $this->executeWithCache(__FUNCTION__, [$email], function () use ($email) {
            return $this->model->where('email', $email)->first();
        });
    }

    public function findByRole(string $role): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$role], function () use ($role) {
            return $this->model->whereHas('roles', function ($query) use ($role) {
                $query->where('name', $role);
            })->get();
        });
    }

    public function updateLastLogin(int $id): bool
    {
        $result = $this->update($id, ['last_login_at' => now()]);
        $this->clearCache($this->model->find($id));
        return $result;
    }

    public function findActive(): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->where('status', 'active')->get();
        });
    }

    public function searchUsers(string $term): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$term], function () use ($term) {
            return $this->model->where('name', 'LIKE', "%{$term}%")
                             ->orWhere('email', 'LIKE', "%{$term}%")
                             ->get();
        });
    }
}
