<?php

namespace App\Core\User\Repositories;

use App\Core\User\Models\User;
use App\Core\Repository\BaseRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class UserRepository extends BaseRepository
{
    public function model(): string
    {
        return User::class;
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);
        return $user->fresh();
    }

    public function delete(User $user): bool
    {
        return $user->delete();
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function findWithRelations(int $id): ?User
    {
        return User::with(['roles', 'permissions', 'activities'])
                  ->find($id);
    }

    public function paginateWithFilters(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = User::query();

        if (!empty($filters['search'])) {
            $query->where(function($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('email', 'like', "%{$filters['search']}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['role'])) {
            $query->whereHas('roles', function($q) use ($filters) {
                $q->where('name', $filters['role']);
            });
        }

        return $query->with(['roles', 'permissions'])
                    ->orderBy($filters['sort_by'] ?? 'created_at', $filters['sort_direction'] ?? 'desc')
                    ->paginate($perPage);
    }

    public function getByRole(string $role): Collection
    {
        return User::whereHas('roles', function($query) use ($role) {
            $query->where('name', $role);
        })->get();
    }

    public function getByPermission(string $permission): Collection
    {
        return User::whereHas('permissions', function($query) use ($permission) {
            $query->where('name', $permission);
        })->get();
    }

    public function suspend(User $user, ?string $reason = null): void
    {
        $user->update([
            'status' => 'suspended',
            'metadata' => array_merge($user->metadata ?? [], [
                'suspension_reason' => $reason,
                'suspended_at' => now()->toDateTimeString()
            ])
        ]);
    }

    public function activate(User $user): void
    {
        $user->update([
            'status' => 'active',
            'metadata' => array_merge($user->metadata ?? [], [
                'activated_at' => now()->toDateTimeString()
            ])
        ]);
    }

    public function updateLastLogin(User $user): void
    {
        $user->update([
            'last_login_at' => now()
        ]);
    }
}
