<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Collection;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    protected array $searchableFields = ['name', 'email', 'username'];
    protected array $filterableFields = ['status', 'role'];
    protected array $relationships = ['role', 'permissions'];

    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function findByEmail(string $email): ?User
    {
        return Cache::remember(
            $this->getCacheKey("email.{$email}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)->where('email', $email)->first()
        );
    }

    public function findByUsername(string $username): ?User
    {
        return Cache::remember(
            $this->getCacheKey("username.{$username}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)->where('username', $username)->first()
        );
    }

    public function getByRole(string $role): Collection
    {
        return Cache::remember(
            $this->getCacheKey("role.{$role}"),
            $this->cacheTTL,
            fn() => $this->model->whereHas('role', function($query) use ($role) {
                $query->where('name', $role);
            })->get()
        );
    }

    public function updateProfile(int $id, array $data): User
    {
        try {
            DB::beginTransaction();
            
            $user = $this->findOrFail($id);
            $user->update($data);
            
            if (isset($data['permissions'])) {
                $user->permissions()->sync($data['permissions']);
            }
            
            DB::commit();
            $this->clearModelCache();
            return $user;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to update user profile: {$e->getMessage()}");
        }
    }
}
