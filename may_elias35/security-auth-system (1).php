namespace App\Core\Security\Auth;

class AuthenticationManager implements AuthenticationInterface
{
    private UserProvider $users;
    private TokenManager $tokens;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private SecurityConfig $config;
    private RateLimiter $rateLimiter;
    private MetricsCollector $metrics;

    public function __construct(
        UserProvider $users,
        TokenManager $tokens,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        SecurityConfig $config,
        RateLimiter $rateLimiter,
        MetricsCollector $metrics
    ) {
        $this->users = $users;
        $this->tokens = $tokens;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
        $this->rateLimiter = $rateLimiter;
        $this->metrics = $metrics;
    }

    public function authenticate(array $credentials, array $factors = []): AuthResult
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            // Rate limiting check
            $this->checkRateLimit($credentials);

            // Primary authentication
            $user = $this->performPrimaryAuth($credentials);

            // Multi-factor authentication if required
            if ($this->requiresMFA($user)) {
                $this->verifyMFAFactors($user, $factors);
            }

            // Generate session/tokens
            $tokens = $this->generateAuthTokens($user);

            // Log successful authentication
            $this->logSuccessfulAuth($user);

            DB::commit();

            $this->metrics->recordAuth('success', microtime(true) - $startTime);

            return new AuthResult(true, $user, $tokens);

        } catch (AuthenticationException $e) {
            DB::rollBack();
            $this->handleFailedAuth($credentials, $e);
            $this->metrics->recordAuth('failure', microtime(true) - $startTime);
            throw $e;
        }
    }

    public function validateSession(string $token): SessionValidation
    {
        try {
            // Decrypt and validate token
            $session = $this->tokens->validate($token);
            
            // Check expiration
            if ($this->isSessionExpired($session)) {
                throw new SessionExpiredException();
            }

            // Verify user still valid
            $user = $this->users->findById($session->getUserId());
            if (!$user || !$user->isActive()) {
                throw new InvalidUserException();
            }

            // Refresh session if needed
            if ($this->shouldRefreshSession($session)) {
                $session = $this->refreshSession($session);
            }

            return new SessionValidation(true, $user, $session);

        } catch (SessionException $e) {
            $this->handleInvalidSession($token, $e);
            throw $e;
        }
    }

    private function performPrimaryAuth(array $credentials): User
    {
        $user = $this->users->findByCredentials($credentials);
        
        if (!$user || !$this->verifyCredentials($user, $credentials)) {
            throw new InvalidCredentialsException();
        }

        if (!$user->isActive()) {
            throw new InactiveUserException();
        }

        return $user;
    }

    private function verifyMFAFactors(User $user, array $factors): void
    {
        foreach ($user->getRequiredMFAFactors() as $requiredFactor) {
            if (!isset($factors[$requiredFactor])) {
                throw new MissingMFAFactorException($requiredFactor);
            }

            if (!$this->verifyFactor($user, $requiredFactor, $factors[$requiredFactor])) {
                throw new InvalidMFAFactorException($requiredFactor);
            }
        }
    }

    private function generateAuthTokens(User $user): array
    {
        return [
            'access_token' => $this->tokens->generateAccessToken($user),
            'refresh_token' => $this->tokens->generateRefreshToken($user),
            'expires_in' => $this->config->get('auth.token_lifetime')
        ];
    }

    private function checkRateLimit(array $credentials): void
    {
        $key = $this->getRateLimitKey($credentials);
        
        if (!$this->rateLimiter->attempt($key)) {
            throw new RateLimitExceededException();
        }
    }
}

class AuthorizationManager implements AuthorizationInterface
{
    private RoleManager $roles;
    private PermissionManager $permissions;
    private AuditLogger $auditLogger;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function authorize(User $user, string $permission, array $context = []): bool
    {
        $startTime = microtime(true);

        try {
            // Check user status
            if (!$user->isActive()) {
                throw new InactiveUserException();
            }

            // Get user roles
            $roles = $this->roles->getUserRoles($user);

            // Check permission
            $hasPermission = $this->checkPermission($roles, $permission);
            
            // Context-based checks
            if ($hasPermission && !empty($context)) {
                $hasPermission = $this->validateContext($user, $permission, $context);
            }

            // Log authorization check
            $this->logAuthCheck($user, $permission, $context, $hasPermission);

            // Record metrics
            $this->metrics->recordAuthorization(
                $permission,
                $hasPermission,
                microtime(true) - $startTime
            );

            return $hasPermission;

        } catch (AuthorizationException $e) {
            $this->handleAuthError($user, $permission, $e);
            throw $e;
        }
    }

    private function validateContext(User $user, string $permission, array $context): bool
    {
        foreach ($this->getContextValidators($permission) as $validator) {
            if (!$validator->validate($user, $context)) {
                return false;
            }
        }
        return true;
    }

    private function getContextValidators(string $permission): array
    {
        return $this->config->get("auth.context_validators.$permission", []);
    }
}
