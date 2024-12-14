<?php

namespace App\Core\User\Services;

use App\Core\User\Models\User;
use App\Core\User\Repositories\UserRepository;
use App\Core\User\Events\{UserCreated, UserUpdated, UserDeleted};
use Illuminate\Support\Facades\{Hash, DB, Event};
use App\Core\User\Services\Permissions\PermissionService;

class UserService
{
    public function __construct(
        private UserRepository $repository,
        private UserValidator $validator,
        private PermissionService $permissionService
    ) {}

    public function create(array $data): User
    {
        $this->validator->validateCreate($data);

        return DB::transaction(function () use ($data) {
            $data['password'] = Hash::make($data['password']);
            
            $user = $this->repository->create($data);
            
            if (!empty($data['roles'])) {
                $this->permissionService->assignRoles($user, $data['roles']);
            }

            if (!empty($data['permissions'])) {
                $this->permissionService->assignPermissions($user, $data['permissions']);
            }

            event(new UserCreated($user));
            
            return $user;
        });
    }

    public function update(User $user, array $data): User
    {
        $this->validator->validateUpdate($user, $data);

        return DB::transaction(function () use ($user, $data) {
            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            $user = $this->repository->update($user, $data);

            if (isset($data['roles'])) {
                $this->permissionService->syncRoles($user, $data['roles']);
            }

            if (isset($data['permissions'])) {
                $this->permissionService->syncPermissions($user, $data['permissions']);
            }

            event(new UserUpdated($user));
            
            return $user;
        });
    }

    public function delete(User $user): bool
    {
        $this->validator->validateDelete($user);

        return DB::transaction(function () use ($user) {
            $deleted = $this->repository->delete($user);
            
            if ($deleted) {
                event(new UserDeleted($user));
            }
            
            return $deleted;
        });
    }

    public function assignRole(User $user, string $role): void
    {
        $this->validator->validateRole($role);
        $this->permissionService->assignRole($user, $role);
    }

    public function removeRole(User $user, string $role): void
    {
        $this->validator->validateRole($role);
        $this->permissionService->removeRole($user, $role);
    }

    public function syncRoles(User $user, array $roles): void
    {
        $this->validator->validateRoles($roles);
        $this->permissionService->syncRoles($user, $roles);
    }

    public function assignPermission(User $user, string $permission): void
    {
        $this->validator->validatePermission($permission);
        $this->permissionService->assignPermission($user, $permission);
    }

    public function removePermission(User $user, string $permission): void
    {
        $this->validator->validatePermission($permission);
        $this->permissionService->removePermission($user, $permission);
    }

    public function syncPermissions(User $user, array $permissions): void
    {
        $this->validator->validatePermissions($permissions);
        $this->permissionService->syncPermissions($user, $permissions);
    }

    public function updateProfile(User $user, array $data): User
    {
        $this->validator->validateProfileUpdate($user, $data);
        return $this->update($user, $data);
    }

    public function updatePassword(User $user, string $password): User
    {
        $this->validator->validatePassword($password);
        return $this->update($user, ['password' => $password]);
    }

    public function suspend(User $user, ?string $reason = null): void
    {
        $this->validator->validateSuspension($user);
        $this->repository->suspend($user, $reason);
    }

    public function activate(User $user): void
    {
        $this->repository->activate($user);
    }

    public function getAccessibleResources(User $user): array
    {
        return $this->permissionService->getAccessibleResources($user);
    }
}
