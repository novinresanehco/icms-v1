<?php

namespace App\Core\Auth;

class AuthManager
{
    private ValidationService $validator;
    private AuditLogger $audit;
    private array $permissions;

    public function hasPermissions(User $user, array $requiredPermissions): bool
    {
        foreach ($requiredPermissions as $permission) {
            if (!$this->hasPermission($user, $permission)) {
                return false;
            }
        }
        return true;
    }

    public function hasPermission(User $user, string $permission): bool
    {
        $userPermissions = $this->getUserPermissions($user);
        return in_array($permission, $userPermissions);
    }

    private function getUserPermissions(User $user): array
    {
        if (!isset($this->permissions[$user->id])) {
            $this->permissions[$user->id] = $this->loadUserPermissions($user);
        }
        return $this->permissions[$user->id];
    }

    private function loadUserPermissions(User $user): array
    {
        $permissions = [];
        foreach ($user->roles as $role) {
            $permissions = array_merge($permissions, $role->permissions);
        }
        return array_unique($permissions);
    }
}

class SecurityContext
{
    private User $user;
    private Request $request;
    private array $metadata;

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

interface User
{
    public function getId(): int;
    public function getPermissions(): array;
    public function getRoles(): array;
    public function hasPermission(string $permission): bool;
    public function hasRole(string $role): bool;
}

class Role
{
    private string $name;
    private array $permissions;

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions);
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }
}

class Permission
{
    private string $name;
    private string $description;
    private array $roles;

    public function getRoles(): array
    {
        return $this->roles;
    }
}

interface AuthProviderInterface
{
    public function verifyCredentials(array $credentials): bool;
    public function getUserById(int $id): ?User;
    public function createToken(User $user): string;
    public function validateToken(string $token): bool;
    public function revokeToken(string $token): void;
}

class AuthProvider implements AuthProviderInterface
{
    private UserRepository $users;
    private TokenManager $tokens;
    private SecurityManager $security;

    public function verifyCredentials(array $credentials): bool
    {
        try {
            $user = $this->users->findByEmail($credentials['email']);
            
            if (!$user) {
                return false;
            }

            return $this->security->verifyPassword(
                $credentials['password'],
                $user->getPassword()
            );

        } catch (\Exception $e) {
            throw new AuthenticationException('Credential verification failed', 0, $e);
        }
    }

    public function getUserById(int $id): ?User
    {
        try {
            return $this->users->find($id);
        } catch (\Exception $e) {
            throw new AuthenticationException('User lookup failed', 0, $e);
        }
    }

    public function createToken(User $user): string
    {
        try {
            