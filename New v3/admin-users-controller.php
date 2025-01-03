<?php

namespace App\Http\Controllers\Admin;

use App\Core\User\{UserManager, RoleManager, GroupManager};
use App\Core\Security\{AccessControl, AuditLogger};
use App\Core\Cache\CacheManager;

class AdminUsersController extends Controller 
{
    private UserManager $users;
    private RoleManager $roles;
    private GroupManager $groups;
    private AccessControl $access;
    private AuditLogger $audit;
    private CacheManager $cache;

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);
        
        try {
            DB::beginTransaction();

            $user = $this->users->create($request->validated());
            
            if ($request->has('roles')) {
                $this->roles->assignToUser($user, $request->input('roles'));
            }

            if ($request->has('groups')) {
                $this->groups->assignToUser($user, $request->input('groups'));
            }

            $this->audit->logUserCreation($user, $request->user());

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
                $this->roles->syncUserRoles($user, $request->input('roles'));
            }

            if ($request->has('groups')) {
                $this->groups->syncUserGroups($user, $request->input('groups'));
            }

            $this->audit->logUserUpdate($user, $request->user());

            DB::commit();
            
            $this->cache->invalidateUserCache($user);

            return response()->json($user);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function assignRole(AssignRoleRequest $request, int $id): JsonResponse
    {
        $user = $this->users->findOrFail($id);
        $this->authorize('assignRole', $user);

        try {
            DB::beginTransaction();

            $role = $this->roles->findOrFail($request->input('role_id'));
            
            $this->roles->assignToUser($user, $role);
            $this->access->recalculatePermissions($user);
            
            $this->audit->logRoleAssignment($user, $role, $request->user());

            DB::commit();
            
            $this->cache->invalidateUserCache($user);

            return response()->json($user->fresh('roles'));
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function lock(int $id): JsonResponse
    {
        $user = $this->users->findOrFail($id);
        $this->authorize('lock', $user);

        $this->users->lock($user);
        $this->audit->logUserLocked($user, auth()->user());
        $this->cache->invalidateUserCache($user);

        return response()->json(['message' => 'User locked successfully']);
    }

    public function unlock(int $id): JsonResponse
    {
        $user = $this->users->findOrFail($id);
        $this->authorize('unlock', $user);

        $this->users->unlock($user);
        $this->audit->logUserUnlocked($user, auth()->user());
        $this->cache->invalidateUserCache($user);

        return response()->json(['message' => 'User unlocked successfully']);
    }

    public function activity(int $id): JsonResponse
    {
        $user = $this->users->findOrFail($id);
        $this->authorize('viewActivity', $user);

        return response()->json([
            'login_history' => $this->audit->getUserLogins($user),
            'action_history' => $this->audit->getUserActions($user),
            'permission_changes' => $this->audit->getPermissionChanges($user)
        ]);
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
