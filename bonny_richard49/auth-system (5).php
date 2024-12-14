<?php

namespace App\Core\Auth;

use App\Core\Security\CoreSecurityManager;
use Illuminate\Support\Facades\{Hash, DB, Cache};
use Firebase\JWT\JWT;

class AuthenticationSystem
{
    private CoreSecurityManager $security;
    private UserRepository $users;
    private TokenManager $tokens;
    
    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeSecureOperation(
            fn() => $this->verifyCredentials($credentials),
            ['action' => 'auth.login']
        );
    }

    private function verifyCredentials(array $credentials): AuthResult
    {
        $user = $this->users->findByUsername($credentials['username']);
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        return new AuthResult(
            $user,
            $this->tokens->createToken($user)
        );
    }
}

class TokenManager
{
    private string $key;
    private int $ttl = 3600;

    public function createToken(User $user): string
    {
        $token = JWT::encode([
            'uid' => $user->id,
            'exp' => time() + $this->ttl
        ], $this->key, 'HS256');

        Cache::put("token:$token", [
            'user_id' => $user->id,
            'expires' => time() + $this->ttl
        ], $this->ttl);

        return $token;
    }

    public function validateToken(string $token): ?array
    {
        try {
            $payload = JWT::decode($token, $this->key, ['HS256']);
            return Cache::get("token:$token");
        } catch (\Exception $e) {
            return null;
        }
    }
}

class UserRepository
{
    public function findByUsername(string $username): ?User
    {
        return DB::table('users')
            ->where('username', $username)
            ->first();
    }

    public function findById(int $id): ?User 
    {
        return Cache::remember(
            "user:$id",
            3600,
            fn() => DB::table('users')->find($id)
        );
    }
}

class PermissionManager
{
    private array $permissions = [];

    public function can(User $user, string $permission): bool
    {
        return Cache::remember(
            "permissions:{$user->id}:{$permission}",
            3600,
            fn() => $this->checkPermission($user, $permission)
        );
    }

    private function checkPermission(User $user, string $permission): bool
    {
        $roles = DB::table('user_roles')
            ->where('user_id', $user->id)
            ->pluck('role_id');

        return DB::table('role_permissions')
            ->whereIn('role_id', $roles)
            ->where('permission', $permission)
            ->exists();
    }
}

class User
{
    public int $id;
    public string $username;
    public string $password;
    public array $roles = [];
    public bool $active = true;
}

class AuthResult
{
    public function __construct(
        public User $user,
        public string $token
    ) {}
}
