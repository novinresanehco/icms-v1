<?php

namespace App\Core\Repositories;

use App\Models\User;
use Illuminate\Support\Collection;

class UserRepository extends AdvancedRepository
{
    protected $model = User::class;
    protected array $searchable = ['name', 'email'];

    public function findByEmail(string $email): ?User
    {
        return $this->executeWithCache(__METHOD__, function() use ($email) {
            return $this->model->where('email', $email)->first();
        }, $email);
    }

    public function createUser(array $data): User
    {
        return $this->executeTransaction(function() use ($data) {
            $user = $this->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => bcrypt($data['password']),
                'status' => $data['status'] ?? 'active'
            ]);

            if (isset($data['roles'])) {
                $user->roles()->sync($data['roles']);
            }

            return $user;
        });
    }

    public function updateProfile(int $userId, array $data): User
    {
        return $this->executeTransaction(function() use ($userId, $data) {
            $user = $this->findOrFail($userId);
            
            $updateData = array_filter([
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'password' => isset($data['password']) ? bcrypt($data['password']) : null
            ]);

            $user->update($updateData);

            if (isset($data['roles'])) {
                $user->roles()->sync($data['roles']);
            }

            $this->invalidateCache('findByEmail', $user->email);
            
            return $user;
        });
    }

    public function search(array $criteria, array $with = []): Collection
    {
        return $this->executeQuery(function() use ($criteria, $with) {
            $query = $this->model->newQuery();

            foreach ($this->searchable as $field) {
                if (isset($criteria[$field])) {
                    $query->where($field, 'LIKE', "%{$criteria[$field]}%");
                }
            }

            if (!empty($with)) {
                $query->with($with);
            }

            if (isset($criteria['role'])) {
                $query->whereHas('roles', function($q) use ($criteria) {
                    $q->where('name', $criteria['role']);
                });
            }

            if (isset($criteria['status'])) {
                $query->where('status', $criteria['status']);
            }

            return $query->get();
        });
    }

    public function updateLastLogin(int $userId): void
    {
        $this->executeTransaction(function() use ($userId) {
            $this->model->where('id', $userId)->update([
                'last_login_at' => now()
            ]);
        });
    }

    public function deactivate(int $userId): void
    {
        $this->executeTransaction(function() use ($userId) {
            $user = $this->findOrFail($userId);
            $user->update(['status' => 'inactive']);
            $this->invalidateCache('findByEmail', $user->email);
        });
    }

    public function getActiveUsersCount(): int
    {
        return $this->executeWithCache(__METHOD__, function() {
            return $this->model->where('status', 'active')->count();
        });
    }
}
