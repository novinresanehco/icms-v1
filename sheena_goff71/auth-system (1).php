<?php
namespace App\Core\Auth;

class AuthenticationService implements AuthenticationInterface
{
    private TokenManager $tokens;
    private UserRepository $users;
    private SecurityManager $security;
    private AuditLogger $audit;

    public function authenticate(Request $request): AuthResult 
    {
        DB::beginTransaction();
        try {
            // Multi-factor authentication
            $credentials = $this->validateCredentials($request);
            $user = $this->validateUser($credentials);
            $mfa = $this->validateMFA($user, $request);
            
            // Generate secure session
            $token = $this->tokens->generate($user, [
                'ip' => $request->ip(),
                'device' => $request->userAgent(),
                'mfa_verified' => true
            ]);

            // Create audit trail
            $this->audit->logAuthentication($user, $token, $request);

            DB::commit();
            return new AuthResult($user, $token);

        } catch (AuthException $e) {
            DB::rollBack();
            $this->handleFailedAttempt($e, $request);
            throw $e;
        }
    }

    protected function validateCredentials(Request $request): array
    {
        $credentials = $request->only(['email', 'password']);
        
        if (!$this->security->validateInput($credentials)) {
            throw new AuthException('Invalid credentials format');
        }

        return $credentials;
    }

    protected function validateUser(array $credentials): User
    {
        $user = $this->users->findByEmail($credentials['email']);
        
        if (!$user || !$this->verifyPassword($user, $credentials['password'])) {
            $this->audit->logFailedAttempt($credentials);
            throw new AuthException('Invalid credentials');
        }

        if ($user->isLocked() || $user->requiresPasswordChange()) {
            throw new AccountException('Account requires attention');
        }

        return $user;
    }

    protected function validateMFA(User $user, Request $request): bool
    {
        if ($user->hasMFAEnabled()) {
            $token = $request->input('mfa_token');
            
            if (!$this->tokens->verifyMFA($user, $token)) {
                throw new MFAException('Invalid MFA token');
            }
        }

        return true;
    }

    protected function handleFailedAttempt(AuthException $e, Request $request): void
    {
        // Rate limiting and brute force protection
        $key = 'auth_attempts:' . $request->ip();
        $attempts = Cache::increment($key);

        if ($attempts > config('auth.max_attempts')) {
            $this->security->blockIP($request->ip());
            $this->audit->logSecurityThreat('Excessive auth attempts', $request);
        }
    }

    protected function verifyPassword(User $user, string $password): bool
    {
        return password_verify(
            $password,
            $user->password_hash
        );
    }
}

class AuthorizationService implements AuthorizationInterface 
{
    private PermissionRepository $permissions;
    private RoleRepository $roles;
    private AuditLogger $audit;

    public function authorize(User $user, string $permission): bool
    {
        try {
            // Check direct permissions
            if ($user->hasDirectPermission($permission)) {
                return true;
            }

            // Check role-based permissions
            foreach ($user->roles as $role) {
                if ($this->roles->hasPermission($role, $permission)) {
                    return true;
                }
            }

            // Log unauthorized attempt
            $this->audit->logUnauthorizedAccess($user, $permission);
            return false;

        } catch (\Exception $e) {
            $this->audit->logError('Authorization error', $e);
            throw new AuthorizationException('Authorization check failed', 0, $e);
        }
    }

    public function validatePermissions(array $requiredPermissions): bool
    {
        return collect($requiredPermissions)->every(function($permission) {
            return $this->permissions->exists($permission);
        });
    }

    public function getRolePermissions(Role $role): Collection
    {
        return $this->permissions->getForRole($role);
    }
}

class TokenManager
{
    private string $key;
    private int $lifetime;

    public function generate(User $user, array $context = []): Token
    {
        $payload = array_merge([
            'sub' => $user->id,
            'iat' => time(),
            'exp' => time() + $this->lifetime,
            'jti' => Str::random(32)
        ], $context);

        return new Token(
            JWT::encode($payload, $this->key)
        );
    }

    public function verify(string $token): ?array
    {
        try {
            return JWT::decode($token, $this->key, ['HS256']);
        } catch (JWTException $e) {
            return null;
        }
    }

    public function invalidate(string $token): void
    {
        Cache::put(
            "invalidated_token:{$token}",
            true,
            now()->addMinutes(30)
        );
    }
}
