namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Auth\Models\{Role, Permission, User};
use Illuminate\Support\Facades\{DB, Cache, Event};

class PermissionManagementService implements PermissionManagementInterface
{
    private SecurityManager $security;
    private RoleRepository $roles;
    private PermissionRepository $permissions;
    private ValidationService $validator;
    private AuditLogger $logger;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        RoleRepository $roles,
        PermissionRepository $permissions,
        ValidationService $validator,
        AuditLogger $logger,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    public function hasPermission(User $user, string $permission): bool
    {
        return $this->security->executeCriticalOperation(
            new CheckPermissionOperation($user->id, $permission),
            new SecurityContext(['user_id' => $user->id]),
            function() use ($user, $permission) {
                return $this->cache->remember(
                    "user_permission:{$user->id}:{$permission}",
                    function() use ($user, $permission) {
                        return $this->checkUserPermission($user, $permission);
                    }
                );
            }
        );
    }

    public function assignRole(User $user, Role $role, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new AssignRoleOperation($user->id, $role->id),
            $context,
            function() use ($user, $role) {
                DB::transaction(function() use ($user, $role) {
                    $this->validator->validateRoleAssignment($user, $role);
                    
                    $user->roles()->attach($role->id);
                    $this->clearUserPermissionCache($user);
                    $this->logger->logRoleAssignment($user, $role);
                    
                    Event::dispatch(new RoleAssignedEvent($user, $role));
                });
            }
        );
    }

    public function revokeRole(User $user, Role $role, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new RevokeRoleOperation($user->id, $role->id),
            $context,
            function() use ($user, $role) {
                DB::transaction(function() use ($user, $role) {
                    $user->roles()->detach($role->id);
                    $this->clearUserPermissionCache($user);
                    $this->logger->logRoleRevocation($user, $role);
                    
                    Event::dispatch(new RoleRevokedEvent($user, $role));
                });
            }
        );
    }

    public function createRole(array $data, SecurityContext $context): Role
    {
        return $this->security->executeCriticalOperation(
            new CreateRoleOperation($data),
            $context,
            function() use ($data) {
                $validated = $this->validator->validateRole($data);
                
                return DB::transaction(function() use ($validated) {
                    $role = $this->roles->create($validated);
                    $this->logger->logRoleCreation($role);
                    
                    Event::dispatch(new RoleCreatedEvent($role));
                    
                    return $role;
                });
            }
        );
    }

    public function updateRole(Role $role, array $data, SecurityContext $context): Role
    {
        return $this->security->executeCriticalOperation(
            new UpdateRoleOperation($role->id, $data),
            $context,
            function() use ($role, $data) {
                $validated = $this->validator->validateRole($data);
                
                return DB::transaction(function() use ($role, $validated) {
                    $updated = $this->roles->update($role->id, $validated);
                    $this->clearRolePermissionCache($role);
                    $this->logger->logRoleUpdate($updated);
                    
                    Event::dispatch(new RoleUpdatedEvent($updated));
                    
                    return $updated;
                });
            }
        );
    }

    public function syncRolePermissions(Role $role, array $permissions, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new SyncPermissionsOperation($role->id, $permissions),
            $context,
            function() use ($role, $permissions) {
                DB::transaction(function() use ($role, $permissions) {
                    $this->validator->validatePermissions($permissions);
                    
                    $role->permissions()->sync($permissions);
                    $this->clearRolePermissionCache($role);
                    $this->logger->logPermissionSync($role, $permissions);
                    
                    Event::dispatch(new PermissionsSyncedEvent($role));
                });
            }
        );
    }

    private function checkUserPermission(User $user, string $permission): bool
    {
        return $user->roles()
            ->with('permissions')
            ->get()
            ->flatMap->permissions
            ->pluck('name')
            ->contains($permission);
    }

    private function clearUserPermissionCache(User $user): void
    {
        $this->cache->tags(['user_permissions'])->forget("user:{$user->id}:permissions");
        
        foreach ($user->roles as $role) {
            $this->clearRolePermissionCache($role);
        }
    }

    private function clearRolePermissionCache(Role $role): void
    {
        $this->cache->tags(['role_permissions'])->forget("role:{$role->id}:permissions");
        
        $users = $role->users()->get();
        foreach ($users as $user) {
            $this->clearUserPermissionCache($user);
        }
    }

    private function validatePermissionAccess(User $user, string $permission): void
    {
        if (!$this->hasPermission($user, $permission)) {
            $this->logger->logUnauthorizedAccess($user, $permission);
            throw new UnauthorizedException("User does not have required permission: {$permission}");
        }
    }
}
