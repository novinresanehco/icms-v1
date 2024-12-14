```php
<?php
namespace App\Core\Auth;

class AuthenticationManager implements AuthenticationInterface
{
    private TokenService $tokens;
    private HashingService $hasher;
    private SecurityValidator $validator;
    private AuditLogger $logger;
    private int $maxAttempts = 3;

    public function authenticate(array $credentials): AuthResult
    {
        $attemptId = $this->generateAttemptId();
        
        try {
            $this->validateCredentials($credentials);
            $this->checkRateLimit($credentials);
            
            DB::beginTransaction();
            
            $user = $this->verifyCredentials($credentials);
            $token = $this->generateUserToken($user);
            
            $this->logger->logSuccessfulAuth($attemptId, $user);
            DB::commit();
            
            return new AuthResult($user, $token);
        } catch (AuthException $e) {
            DB::rollBack();
            $this->handleFailedAttempt($attemptId, $credentials, $e);
            throw $e;
        }
    }

    public function verify(string $token): User
    {
        try {
            $payload = $this->tokens->verify($token);
            return $this->validateUser($payload->userId);
        } catch (\Exception $e) {
            $this->logger->logInvalidToken($token);
            throw new AuthException('Invalid token', 0, $e);
        }
    }

    private function verifyCredentials(array $credentials): User
    {
        $user = User::findByUsername($credentials['username']);
        
        if (!$user || !$this->hasher->verify($credentials['password'], $user->password)) {
            throw new AuthException('Invalid credentials');
        }
        
        return $user;
    }
}

class AuthorizationManager implements AuthorizationInterface
{
    private RoleManager $roles;
    private PermissionCache $cache;
    private SecurityValidator $validator;
    private AuditLogger $logger;

    public function authorize(User $user, string $permission): bool
    {
        $authId = $this->generateAuthId();
        
        try {
            $this->validator->validatePermission($permission);
            
            $result = $this->cache->remember("user:{$user->id}:perm:{$permission}", function() use ($user, $permission) {
                return $this->roles->hasPermission($user->role, $permission);
            });
            
            $this->logger->logAuthorizationCheck($authId, $user, $permission, $result);
            return $result;
            
        } catch (\Exception $e) {
            $this->handleAuthorizationFailure($authId, $user, $permission, $e);
            throw new AuthorizationException('Authorization check failed', 0, $e);
        }
    }

    public function getUserPermissions(User $user): array
    {
        return $this->cache->remember("user:{$user->id}:permissions", function() use ($user) {
            return $this->roles->getAllPermissions($user->role);
        });
    }
}

class RoleManager implements RoleManagerInterface
{
    private SecurityValidator $validator;
    private Cache $cache;
    private AuditLogger $logger;

    public function assignRole(User $user, Role $role): void
    {
        try {
            $this->validator->validateRole($role);
            
            DB::beginTransaction();
            $user->role()->associate($role);
            $user->save();
            
            $this->cache->invalidateUserPermissions($user);
            $this->logger->logRoleAssignment($user, $role);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RoleException('Role assignment failed', 0, $e);
        }
    }

    public function hasPermission(Role $role, string $permission): bool
    {
        return $this->cache->remember("role:{$role->id}:perm:{$permission}", function() use ($role, $permission) {
            return $role->permissions()->where('name', $permission)->exists();
        });
    }
}

interface AuthenticationInterface
{
    public function authenticate(array $credentials): AuthResult;
    public function verify(string $token): User;
}

interface AuthorizationInterface
{
    public function authorize(User $user, string $permission): bool;
    public function getUserPermissions(User $user): array;
}

interface RoleManagerInterface
{
    public function assignRole(User $user, Role $role): void;
    public function hasPermission(Role $role, string $permission): bool;
}
```
