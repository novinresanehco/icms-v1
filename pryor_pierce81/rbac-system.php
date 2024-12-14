<?php

namespace App\Core\Authorization;

class RbacManager
{
    private RoleRepository $roleRepository;
    private PermissionRepository $permissionRepository;
    private CacheManager $cache;

    public function hasPermission(User $user, string $permission): bool
    {
        return $this->cache->remember(
            "user.{$user->getId()}.permission.{$permission}",
            fn() => $this->checkPermission($user, $permission)
        );
    }

    private function checkPermission(User $user, string $permission): bool
    {
        $roles = $this->roleRepository->getRolesForUser($user->getId());
        
        foreach ($roles as $role) {
            if ($role->hasPermission($permission)) {
                return true;
            }
        }
        
        return false;
    }

    public function assignRole(User $user, Role $role): void
    {
        $this->roleRepository->assignRole($user->getId(), $role->getId());
        $this->cache->forget("user.{$user->getId()}.roles");
    }

    public function revokeRole(User $user, Role $role): void
    {
        $this->roleRepository->revokeRole($user->getId(), $role->getId());
        $this->cache->forget("user.{$user->getId()}.roles");
    }

    public function createRole(string $name, array $permissions = []): Role
    {
        $role = new Role($name);
        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }
        $this->roleRepository->save($role);
        return $role;
    }

    public function createPermission(string $name, string $description = ''): Permission
    {
        $permission = new Permission($name, $description);
        $this->permissionRepository->save($permission);
        return $permission;
    }
}

class Role
{
    private string $id;
    private string $name;
    private array $permissions = [];
    private array $metadata = [];

    public function __construct(string $name)
    {
        $this->id = uniqid('role_', true);
        $this->name = $name;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function addPermission(Permission $permission): void
    {
        $this->permissions[$permission->getId()] = $permission;
    }

    public function removePermission(Permission $permission): void
    {
        unset($this->permissions[$permission->getId()]);
    }

    public function hasPermission(string $permissionName): bool
    {
        foreach ($this->permissions as $permission) {
            if ($permission->getName() === $permissionName) {
                return true;
            }
        }
        return false;
    }

    public function getPermissions(): array
    {
        return array_values($this->permissions);
    }

    public function setMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(string $key)
    {
        return $this->metadata[$key] ?? null;
    }
}

class Permission
{
    private string $id;
    private string $name;
    private string $description;
    private array $metadata = [];

    public function __construct(string $name, string $description = '')
    {
        $this->id = uniqid('perm_', true);
        $this->name = $name;
        $this->description = $description;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(string $key)
    {
        return $this->metadata[$key] ?? null;
    }
}

class RoleRepository
{
    private $connection;

    public function save(Role $role): void
    {
        $this->connection->table('roles')->insert([
            'id' => $role->getId(),
            'name' => $role->getName(),
            'metadata' => json_encode($role->getMetadata()),
            'created_at' => now()
        ]);

        foreach ($role->getPermissions() as $permission) {
            $this->connection->table('role_permissions')->insert([
                'role_id' => $role->getId(),
                'permission_id' => $permission->getId()
            ]);
        }
    }

    public function assignRole(string $userId, string $roleId): void
    {
        $this->connection->table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'assigned_at' => now()
        ]);
    }

    public function revokeRole(string $userId, string $roleId): void
    {
        $this->connection->table('user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->delete();
    }

    public function getRolesForUser(string $userId): array
    {
        return $this->connection->table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_id', $userId)
            ->get()
            ->map(fn($row) => $this->hydrate($row))
            ->toArray();
    }

    private function hydrate($row): Role
    {
        $role = new Role($row->name);
        foreach (json_decode($row->metadata, true) as $key => $value) {
            $role->setMetadata($key, $value);
        }
        return $role;
    }
}

class PermissionRepository
{
    private $connection;

    public function save(Permission $permission): void
    {
        $this->connection->table('permissions')->insert([
            'id' => $permission->getId(),
            'name' => $permission->getName(),
            'description' => $permission->getDescription(),
            'metadata' => json_encode($permission->getMetadata()),
            'created_at' => now()
        ]);
    }

    public function getPermissionsForRole(string $roleId): array
    {
        return $this->connection->table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_id', $roleId)
            ->get()
            ->map(fn($row) => $this->hydrate($row))
            ->toArray();
    }

    private function hydrate($row): Permission
    {
        $permission = new Permission($row->name, $row->description);
        foreach (json_decode($row->metadata, true) as $key => $value) {
            $permission->setMetadata($key, $value);
        }
        return $permission;
    }
}
