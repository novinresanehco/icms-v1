namespace App\Core\Security;

class AuthenticationManager implements AuthenticationInterface
{
    private SecurityConfig $config;
    private UserRepository $users;
    private TokenService $tokens;
    private AuditLogger $audit;
    private RateLimiter $limiter;

    public function __construct(
        SecurityConfig $config,
        UserRepository $users,
        TokenService $tokens,
        AuditLogger $audit,
        RateLimiter $limiter
    ) {
        $this->config = $config;
        $this->users = $users;
        $this->tokens = $tokens;
        $this->audit = $audit;
        $this->limiter = $limiter;
    }

    public function authenticate(array $credentials): AuthResult
    {
        if ($this->limiter->isRateLimited('auth', $credentials['ip'])) {
            throw new RateLimitException();
        }

        try {
            $user = $this->users->findByCredentials($credentials);
            
            if (!$user || !$this->validateCredentials($user, $credentials)) {
                $this->handleFailedAttempt($credentials);
                return AuthResult::failed();
            }

            if ($this->requiresTwoFactor($user)) {
                return $this->initiateTwoFactor($user);
            }

            $token = $this->tokens->generate($user, [
                'ip' => $credentials['ip'],
                'device' => $credentials['device']
            ]);

            $this->audit->logSuccessfulLogin($user, $credentials);
            
            return AuthResult::success($user, $token);

        } catch (\Exception $e) {
            $this->audit->logAuthenticationError($e, $credentials);
            throw $e;
        }
    }

    public function validateSession(string $token): SessionInfo
    {
        $session = $this->tokens->verify($token);
        
        if (!$session->isValid()) {
            throw new InvalidSessionException();
        }

        if ($session->isExpired()) {
            throw new SessionExpiredException();
        }

        $this->audit->logSessionValidation($session);
        
        return $session;
    }

    private function validateCredentials(User $user, array $credentials): bool
    {
        if (!$this->passwordHasher->verify($credentials['password'], $user->password)) {
            return false;
        }

        return true;
    }

    private function requiresTwoFactor(User $user): bool
    {
        return $user->hasTwoFactorEnabled() && 
               $this->config->get('auth.two_factor.required');
    }

    private function initiateTwoFactor(User $user): AuthResult
    {
        $challenge = $this->twoFactor->generate($user);
        $this->audit->logTwoFactorInitiated($user);
        return AuthResult::requiresTwoFactor($challenge);
    }

    private function handleFailedAttempt(array $credentials): void
    {
        $this->limiter->increment('auth', $credentials['ip']);
        $this->audit->logFailedLogin($credentials);
    }
}

class AccessControlManager implements AccessControlInterface
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditLogger $audit;

    public function __construct(
        PermissionRegistry $permissions,
        RoleManager $roles,
        AuditLogger $audit
    ) {
        $this->permissions = $permissions;
        $this->roles = $roles;
        $this->audit = $audit;
    }

    public function authorize(User $user, string $permission, ?array $context = null): bool
    {
        try {
            if ($this->hasPermission($user, $permission, $context)) {
                $this->audit->logSuccessfulAccess($user, $permission);
                return true;
            }

            $this->audit->logAccessDenied($user, $permission);
            return false;

        } catch (\Exception $e) {
            $this->audit->logAuthorizationError($e, $user, $permission);
            throw $e;
        }
    }

    public function hasPermission(User $user, string $permission, ?array $context = null): bool
    {
        $roles = $this->roles->getUserRoles($user);
        
        foreach ($roles as $role) {
            if ($this->roleHasPermission($role, $permission, $context)) {
                return true;
            }
        }

        return false;
    }

    private function roleHasPermission(Role $role, string $permission, ?array $context): bool
    {
        $permissions = $this->permissions->getRolePermissions($role);
        
        if (!isset($permissions[$permission])) {
            return false;
        }

        if ($context && !$this->validateContext($permissions[$permission], $context)) {
            return false;
        }

        return true;
    }

    private function validateContext(array $permissionRules, array $context): bool
    {
        foreach ($permissionRules as $rule) {
            if (!$rule->validate($context)) {
                return false;
            }
        }
        return true;
    }

    public function getRolePermissions(Role $role): array
    {
        return $this->permissions->getRolePermissions($role);
    }

    public function refreshPermissions(): void
    {
        $this->permissions->refresh();
    }
}

class TokenService
{
    private string $key;
    private int $ttl;
    private TokenStore $store;

    public function generate(User $user, array $context = []): string
    {
        $token = $this->createToken($user, $context);
        $this->store->save($token);
        return $token->toString();
    }

    public function verify(string $tokenString): SessionInfo
    {
        $token = $this->parseToken($tokenString);
        
        if (!$this->store->exists($token)) {
            throw new InvalidTokenException();
        }

        return new SessionInfo($token);
    }

    private function createToken(User $user, array $context): Token
    {
        return new Token([
            'user_id' => $user->id,
            'expires_at' => time() + $this->ttl,
            'context' => $context
        ]);
    }

    private function parseToken(string $token): Token
    {
        try {
            return Token::parse($token, $this->key);
        } catch (\Exception $e) {
            throw new InvalidTokenException();
        }
    }
}
