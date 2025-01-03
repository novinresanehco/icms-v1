<?php

namespace App\Http\Controllers\Api;

use App\Core\User\{UserManager, RoleManager};
use App\Core\Security\{AccessControl, AuditLogger};
use App\Core\Cache\CacheManager;

class ApiUserController extends Controller
{
    private UserManager $users;
    private RoleManager $roles;
    private AccessControl $access;
    private AuditLogger $audit;
    private CacheManager $cache;

    public function index(IndexUserRequest $request): JsonResponse
    {
        $query = $this->users->newQuery();

        if ($request->has('roles')) {
            $query->whereHasRoles($request->roles);
        }

        if ($request->has('permissions')) {
            $query->whereHasPermissions($request->permissions);
        }

        return response()->json(
            $query->paginate($request->perPage())
        );
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        try {
            DB::beginTransaction();

            $user = $this->users->create($request->validated());

            if ($request->has('roles')) {
                $this->roles->assignToUser($user, $request->roles);
            }

            $this->audit->logUserCreation($user, auth()->user());

            DB::commit();

            $this->cache->invalidateUserCache($user);

            return response()->json($user, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = $this->users->findOrFail($id);
        
        $this->authorize('update', $user);

        try {
            DB::beginTransaction();

            $user = $this->users->update($user, $request->validated());

            if ($request->has('roles')) {
                $this->roles->syncUserRoles($user, $request->roles);
            }

            $this->access->recalculatePermissions($user);
            $this->audit->logUserUpdate($user, auth()->user());

            DB::commit();

            $this->cache->invalidateUserCache($user);

            return response()->json($user);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function permissions(int $id): JsonResponse
    {
        $user = $this->users->findOrFail($id);
        
        $this->authorize('viewPermissions', $user);

        return response()->json([
            'roles' => $user->roles,
            'permissions' => $user->getAllPermissions(),
            'effective_permissions' => $this->access->getEffectivePermissions($user)
        ]);
    }

    public function activity(int $id): JsonResponse
    {
        $user = $this->users->findOrFail($id);
        
        $this->authorize('viewActivity', $user);

        return response()->json([
            'logins' => $this->audit->getUserLogins($user),
            'actions' => $this->audit->getUserActions($user),
            'permission_changes' => $this->audit->getPermissionChanges($user)
        ]);
    }

    public function suspend(int $id): JsonResponse
    {
        $user = $this->users->findOrFail($id);
        
        $this->authorize('suspend', $user);

        $this->users->suspend($user);
        $this->audit->logUserSuspension($user, auth()->user());
        $this->cache->invalidateUserCache($user);

        return response()->json(['message' => 'User suspended successfully']);
    }

    public function reactivate(int $id): JsonResponse
    {
        $user = $this->users->findOrFail($id);
        
        $this->authorize('reactivate', $user);

        $this->users->reactivate($user);
        $this->audit->logUserReactivation($user, auth()->user());
        $this->cache->invalidateUserCache($user);

        return response()->json(['message' => 'User reactivated successfully']);
    }

    protected function validatePermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (!$this->access->permissionExists($permission)) {
                throw new InvalidPermissionException($permission);
            }
        }
    }
}
