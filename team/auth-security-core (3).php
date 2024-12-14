namespace App\Core\Security;

class AuthenticationManager implements AuthenticationInterface
{
    private EncryptionService $encryption;
    private TokenManager $tokenManager;
    private UserRepository $users;
    private AuditLogger $audit;
    private SecurityConfig $config;

    public function authenticate(AuthRequest $request): AuthResult
    {
        DB::beginTransaction();
        
        try {
            // Validate credentials
            $this->validateCredentials($request);
            
            // Check rate limiting
            $this->checkRateLimit($request);
            
            // Verify multi-factor if required
            if ($this->requiresMFA($request)) {
                $this->verifyMFA($request);
            }
            
            // Generate auth token
            $token = $this->createAuthToken($request);
            
            // Log successful authentication
            $this->logSuccess($request);
            
            DB::commit();
            return new AuthResult($token);
            
        } catch (AuthException $e) {
            DB::rollBack();
            $this->handleAuthFailure($e, $request);
            throw $e;
        }
    }

    protected function validateCredentials(AuthRequest $request): void
    {
        $user = $this->users->findByUsername($request->username);
        
        if (!$user || !$this->verifyPassword($request->password, $user->password)) {
            throw new InvalidCredentialsException();
        }
    }

    protected function checkRateLimit(AuthRequest $request): void
    {
        $key = 'auth_attempts:' . $request->getIp();
        
        if (Cache::get($key, 0) >= $this->config->maxAuthAttempts) {
            throw new RateLimitException('Too many authentication attempts');
        }
        
        Cache::increment($key);
        Cache::expire($key, $this->config->rateLimitWindow);
    }

    protected function requiresMFA(AuthRequest $request): bool
    {
        return $this->config->mfaRequired || 
               $this->isSuspiciousRequest($request);
    }

    protected function verifyMFA(AuthRequest $request): void
    {
        if (!$this->tokenManager->verifyMFAToken($request->mfaToken)) {
            throw new MFAVerificationException();
        }
    }

    protected function createAuthToken(AuthRequest $request): AuthToken
    {
        $user = $this->users->findByUsername($request->username);
        
        return $this->tokenManager->createToken([
            'user_id' => $user->id,
            'roles' => $user->roles,
            'permissions' => $user->permissions,
            'ip' => $request->getIp(),
            'device_id' => $request->getDeviceId()
        ]);
    }

    protected function logSuccess(AuthRequest $request): void
    {
        $this->audit->logAuthSuccess([
            'username' => $request->username,
            'ip' => $request->getIp(),
            'device_id' => $request->getDeviceId(),
            'timestamp' => now()
        ]);
    }

    protected function handleAuthFailure(AuthException $e, AuthRequest $request): void
    {
        $this->audit->logAuthFailure([
            'error' => $e->getMessage(),
            'username' => $request->username,
            'ip' => $request->getIp(),
            'device_id' => $request->getDeviceId(),
            'timestamp' => now()
        ]);
    }

    protected function isSuspiciousRequest(AuthRequest $request): bool
    {
        return $this->isUnknownDevice($request) ||
               $this->isUnusualLocation($request) ||
               $this->hasRecentFailures($request);
    }
}

class AuthorizationManager implements AuthorizationInterface
{
    private RoleManager $roleManager;
    private PermissionManager $permissionManager;
    private AuthenticationManager $auth;
    private AuditLogger $audit;

    public function authorize(AuthToken $token, string $permission): AuthorizationResult
    {
        try {
            // Validate token
            $this->validateToken($token);
            
            // Check permissions
            $this->checkPermission($token, $permission);
            
            // Log authorization
            $this->logAuthorization($token, $permission);
            
            return new AuthorizationResult(true);
            
        } catch (AuthorizationException $e) {
            $this->handleAuthorizationFailure($e, $token, $permission);
            throw $e;
        }
    }

    protected function validateToken(AuthToken $token): void
    {
        if (!$this->auth->validateToken($token)) {
            throw new InvalidTokenException();
        }
    }

    protected function checkPermission(AuthToken $token, string $permission): void
    {
        $user = $token->getUser();
        
        if (!$this->hasPermission($user, $permission)) {
            throw new PermissionDeniedException();
        }
    }

    protected function hasPermission(User $user, string $permission): bool
    {
        // Check direct permissions
        if ($this->permissionManager->hasDirectPermission($user, $permission)) {
            return true;
        }
        
        // Check role-based permissions
        foreach ($user->getRoles() as $role) {
            if ($this->roleManager->hasPermission($role, $permission)) {
                return true;
            }
        }
        
        return false;
    }

    protected function logAuthorization(AuthToken $token, string $permission): void
    {
        $this->audit->logAuthorization([
            'user_id' => $token->getUserId(),
            'permission' => $permission,
            'granted' => true,
            'timestamp' => now()
        ]);
    }

    protected function handleAuthorizationFailure(
        AuthorizationException $e, 
        AuthToken $token, 
        string $permission
    ): void {
        $this->audit->logAuthorizationFailure([
            'error' => $e->getMessage(),
            'user_id' => $token->getUserId(),
            'permission' => $permission,
            'timestamp' => now()
        ]);
    }
}
