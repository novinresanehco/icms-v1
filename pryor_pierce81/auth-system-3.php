namespace App\Core\Auth;

class AuthenticationManager implements AuthenticationInterface 
{
    private UserRepository $users;
    private TokenService $tokens;
    private EncryptionService $encryption;
    private MfaProvider $mfa;
    private AuditLogger $logger;
    private SecurityConfig $config;

    public function authenticate(array $credentials): AuthResult 
    {
        DB::beginTransaction();
        
        try {
            // Validate credentials
            $user = $this->validateCredentials($credentials);
            
            // Check account status
            if (!$user->isActive()) {
                throw new AccountLockedException();
            }

            // Verify MFA if enabled
            if ($user->hasMfaEnabled()) {
                $this->verifyMfa($user, $credentials['mfa_code'] ?? null);
            }

            // Generate secure token
            $token = $this->tokens->generate([
                'user_id' => $user->id,
                'permissions' => $user->getAllPermissions(),
                'expires_at' => now()->addMinutes($this->config->get('session.lifetime'))
            ]);

            // Log successful login
            $this->logger->log('auth.login.success', [
                'user_id' => $user->id,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);

            DB::commit();

            return new AuthResult($user, $token);

        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->logger->log('auth.login.failed', [
                'credentials' => $this->encryption->hash($credentials),
                'error' => $e->getMessage(),
                'ip' => request()->ip()
            ]);

            throw $e;
        }
    }

    public function validateToken(string $token): TokenValidationResult
    {
        try {
            // Decrypt and validate token
            $payload = $this->tokens->validate($token);
            
            if ($payload->isExpired()) {
                throw new TokenExpiredException();
            }

            // Get user and verify status
            $user = $this->users->findOrFail($payload->user_id);
            
            if (!$user->isActive()) {
                throw new AccountLockedException();
            }

            // Log token validation
            $this->logger->log('auth.token.validated', [
                'user_id' => $user->id,
                'token_id' => $payload->jti
            ]);

            return new TokenValidationResult($user, $payload);

        } catch (\Exception $e) {
            $this->logger->log('auth.token.invalid', [
                'token_hash' => $this->encryption->hash($token),
                'error' => $e->getMessage()
            ]);

            throw new InvalidTokenException();
        }
    }

    protected function validateCredentials(array $credentials): User
    {
        $user = $this->users->findByEmail($credentials['email']);

        if (!$user || !$this->encryption->verify(
            $credentials['password'], 
            $user->password
        )) {
            throw new InvalidCredentialsException();
        }

        return $user;
    }

    protected function verifyMfa(User $user, ?string $code): void 
    {
        if (!$code) {
            throw new MfaRequiredException();
        }

        if (!$this->mfa->verify($user, $code)) {
            $this->logger->log('auth.mfa.failed', [
                'user_id' => $user->id,
                'ip' => request()->ip()
            ]);
            
            throw new InvalidMfaCodeException();
        }
    }
}

class AuthorizationManager implements AuthorizationInterface
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditLogger $logger;
    private CacheManager $cache;

    public function checkPermission(User $user, string $permission): bool
    {
        return $this->cache->remember(
            "user.{$user->id}.can.{$permission}",
            300,
            function() use ($user, $permission) {
                $hasPermission = $this->roles->userHasPermission(
                    $user->id,
                    $permission
                );

                $this->logger->log('auth.permission.check', [
                    'user_id' => $user->id,
                    'permission' => $permission,
                    'granted' => $hasPermission
                ]);

                return $hasPermission;
            }
        );
    }

    public function assignRole(User $user, string $role): void
    {
        DB::transaction(function() use ($user, $role) {
            $this->roles->assignUserRole($user->id, $role);
            
            $this->cache->tags(['user.permissions'])->forget(
                "user.{$user->id}.*"
            );

            $this->logger->log('auth.role.assigned', [
                'user_id' => $user->id,
                'role' => $role
            ]);
        });
    }

    public function revokeRole(User $user, string $role): void
    {
        DB::transaction(function() use ($user, $role) {
            $this->roles->revokeUserRole($user->id, $role);
            
            $this->cache->tags(['user.permissions'])->forget(
                "user.{$user->id}.*"
            );

            $this->logger->log('auth.role.revoked', [
                'user_id' => $user->id,
                'role' => $role
            ]);
        });
    }

    public function getAllPermissions(User $user): array
    {
        return $this->cache->remember(
            "user.{$user->id}.permissions",
            3600,
            fn() => $this->roles->getUserPermissions($user->id)
        );
    }
}
