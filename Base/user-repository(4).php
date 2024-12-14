<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    protected array $searchableFields = ['name', 'email', 'username'];
    protected array $filterableFields = ['role', 'status', 'is_admin'];
    protected array $relationships = ['roles' => 'sync', 'permissions' => 'sync'];

    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function createUser(array $data): ?User
    {
        try {
            DB::beginTransaction();

            $data['password'] = Hash::make($data['password']);
            $user = $this->create($data);

            if (isset($data['roles'])) {
                $user->roles()->sync($data['roles']);
            }

            if (isset($data['permissions'])) {
                $user->permissions()->sync($data['permissions']);
            }

            DB::commit();
            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create user: ' . $e->getMessage());
            return null;
        }
    }

    public function getByRole(string $role): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey("role.{$role}"),
                $this->cacheTTL,
                fn() => $this->model->whereHas('roles', function($query) use ($role) {
                    $query->where('name', $role);
                })->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get users by role: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function updateProfile(int $userId, array $data): bool
    {
        try {
            DB::beginTransaction();

            $user = $this->find($userId);
            if (!$user) {
                throw new \Exception('User not found');
            }

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $user->update($data);

            if (isset($data['avatar'])) {
                $this->updateAvatar($user, $data['avatar']);
            }

            DB::commit();
            $this->clearModelCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update user profile: ' . $e->getMessage());
            return false;
        }
    }

    protected function updateAvatar(User $user, $avatar): void
    {
        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $path = $avatar->store('avatars', 'public');
        $user->update(['avatar' => $path]);
    }
}
