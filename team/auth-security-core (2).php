<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Events\AuthEvent;
use App\Core\Exceptions\{AuthException, SecurityException};
use App\Models\{User, Role, Permission};
use Illuminate\Support\Facades\{DB, Hash};

class AuthenticationService implements AuthenticationInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private int $maxAttempts = 3;
    private int $decayMinutes = 15;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->cache = $cache;
    }

    public function authenticate(array $credentials): AuthResult 
    {
        return $this->security->executeCriticalOperation(function() use ($credentials) {
            $this->validateLoginAttempts($credentials['email']);

            DB::beginTransaction();
            try {
                $user = $this->validateCredentials($credentials);
                $token = $this->generateSecureToken($user);
                
                $this->updateLoginMetadata($user);
                $this->cacheUserPermissions($user);
                
                event(new AuthEvent('login_success', $user->id));
                
                DB::commit();
                
                return new AuthResult($user, $token);
            } catch (\Exception $e) {
                DB::rollBack();
                $this->handleFailedLogin($credentials['email']);
                throw new AuthException('Authentication failed: ' . $e->getMessage());
            }
        }, ['operation' => 'authenticate']);
    }

    public function validateAccess(int $userId, string $permission): bool 
    {
        $cacheKey = "user.{$userId}.permissions";
        
        $permissions = $this->cache->remember($cacheKey, 60, function() use ($userId) {
            return $this->getUserPermissions($userId);
        });

        if (!in_array($permission, $permissions)) {
            event(new AuthEvent('permission_denied', $userId, [
                'permission' => $permission
            ]));
            return false;
        }

        return true;
    }

    public function refreshToken(string $token): AuthResult 
    {
        return $this->security->executeCriticalOperation(function() use ($token) {
            $userId = $this->validateToken($token);
            
            $user = User::findOrFail($userId);
            $newToken = $this->generateSecureToken($user);
            
            event(new AuthEvent('token_refresh', $user->id));
            
            return new AuthResult($user, $newToken);
        }, ['operation' => 'refresh_token']);
    }

    public function invalidateToken(string $token): void 
    {
        $this->security->executeCriticalOperation(function() use ($token) {
            $userId = $this->validateToken($token);
            
            $this->cache->tags(['auth_tokens'])->forget("token.{$token}");
            event(new AuthEvent('logout', $userId));
            
        }, ['operation' => 'invalidate_token']);
    }

    protected function validateCredentials(array $credentials): User 
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthException('Invalid credentials');
        }

        if (!$user->is_active) {
            throw new AuthException('Account is inactive');
        }

        return $user;
    }

    protected function generateSecureToken(User $user): string 
    {
        $token = bin2hex(random_bytes(32));
        
        $this->cache->tags(['auth_tokens'])->put(
            "token.{$token}",
            $user->id,
            config('auth.token_ttl')
        );

        return $token;
    }

    protected function validateToken(string $token): int 
    {
        $userId = $this->cache->tags(['auth_tokens'])->get("token.{$token}");
        
        if (!$userId) {
            throw new SecurityException('Invalid or expired token');
        }

        return $userId;
    }

    protected function validateLoginAttempts(string $email): void 
    {
        $key = "login_attempts:{$email}";
        $attempts = (int)$this->cache->get($key, 0);

        if ($attempts >= $this->maxAttempts) {
            throw new SecurityException('Too many login attempts. Please try again later.');
        }
    }

    protected function handleFailedLogin(string $email): void 
    {
        $key = "login_attempts:{$email}";
        $attempts = (int)$this->cache->get($key, 0);
        
        $this->cache->put($key, $attempts + 1, $this->decayMinutes * 60);
        
        event(new AuthEvent('login_failed', null, [
            'email' => $email,
            'attempts' => $attempts + 1
        ]));
    }

    protected function updateLoginMetadata(User $user): void 
    {
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip()
        ]);
    }

    protected function cacheUserPermissions(User $user): void 
    {
        $permissions = $this->getUserPermissions($user->id);
        
        $this->cache->put(
            "user.{$user->id}.permissions",
            $permissions,
            config('auth.permissions_ttl')
        );
    }

    protected function getUserPermissions(int $userId): array 
    {
        return DB::table('permissions')
            ->join('role_permission', 'permissions.id', '=', 'role_permission.permission_id')
            ->join('roles', 'role_permission.role_id', '=', 'roles.id')
            ->join('user_role', 'roles.id', '=', 'user_role.role_id')
            ->where('user_role.user_id', $userId)
            ->pluck('permissions.name')
            ->unique()
            ->values()
            ->all();
    }
}
