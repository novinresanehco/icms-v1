<?php

namespace App\Core\Repositories;

use App\Core\Models\User;
use App\Core\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Pagination\LengthAwarePaginator;

class UserRepository implements UserRepositoryInterface
{
    public function __construct(
        private User $model
    ) {}

    public function findById(int $id): ?User
    {
        return $this->model
            ->with(['roles', 'permissions'])
            ->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model
            ->with(['roles', 'permissions'])
            ->where('email', $email)
            ->first();
    }

    public function getAllPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['roles'])
            ->latest()
            ->paginate($perPage);
    }

    public function getByRole(string $role, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with(['roles'])
            ->role($role)
            ->latest()
            ->paginate($perPage);
    }

    public function getAdmins(): Collection
    {
        return $this->model
            ->with(['roles'])
            ->role('admin')
            ->get();
    }

    public function getActiveUsers(): Collection
    {
        return $this->model
            ->with(['roles'])
            ->where('status', 'active')
            ->get();
    }

    public function store(array $data): User
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user = $this->model->create($data);

        if (isset($data['roles'])) {
            $user->assignRole($data['roles']);
        }

        return $user->fresh(['roles', 'permissions']);
    }

    public function update(int $id, array $data): User
    {
        $user = $this->model->findOrFail($id);

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        if (isset($data['roles'])) {
            $user->syncRoles($data['roles']);
        }

        return $user->fresh(['roles', 'permissions']);
    }

    public function delete(int $id): bool
    {
        return $this->model->findOrFail($id)->delete();
    }

    public function restore(int $id): bool
    {
        return $this->model->withTrashed()->findOrFail($id)->restore();
    }

    public function updateLastLogin(int $id): bool
    {
        return $this->model->findOrFail($id)->update([
            'last_login_at' => now()
        ]);
    }

    public function assignRole(int $id, string $role): bool
    {
        $user = $this->model->findOrFail($id);
        $user->assignRole($role);
        return true;
    }

    public function removeRole(int $id, string $role): bool
    {
        $user = $this->model->findOrFail($id);
        $user->removeRole($role);
        return true;
    }
}
