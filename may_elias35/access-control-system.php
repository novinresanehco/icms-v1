// File: app/Core/Permissions/Access/AccessControlList.php
<?php

namespace App\Core\Permissions\Access;

class AccessControlList
{
    protected array $acl = [];
    protected ACLLoader $loader;
    protected ACLValidator $validator;

    public function allow(string $role, string $resource, array $permissions): void
    {
        $this->validator->validate($role, $resource, $permissions);
        
        if (!isset($this->acl[$role])) {
            $this->acl[$role] = [];
        }
        
        if (!isset($this->acl[$role][$resource])) {
            $this->acl[$role][$resource] = [];
        }
        
        $this->acl[$role][$resource] = array_merge(
            $this->acl[$role][$resource],
            $permissions
        );
    }

    public function deny(string $role, string $resource, array $permissions): void
    {
        if (isset($this->acl[$role][$resource])) {
            $this->acl[$role][$resource] = array_diff(
                $this->acl[$role][$resource],
                $permissions
            );
        }
    }

    public function isAllowed(string $role, string $resource, string $permission): bool
    {
        return isset($this->acl[$role][$resource]) &&
               in_array($permission, $this->acl[$role][$resource]);
    }
}

// File: app/Core/Permissions/Access/ResourceGuard.php
<?php

namespace App\Core\Permissions\Access;

class ResourceGuard
{
    protected AccessControlList $acl;
    protected RoleManager $roleManager;
    protected GuardConfig $config;

    public function guard($resource, string $permission): bool
    {
        $user = auth()->user();
        
        if (!$user) {
            return false;
        }

        foreach ($user->getRoles() as $role) {
            if ($this->acl->isAllowed($role, get_class($resource), $permission)) {
                return true;
            }
        }

        return false;
    }
}
