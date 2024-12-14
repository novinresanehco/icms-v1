<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Core\Security\{SecurityContext, MultiFactorService};
use App\Core\Services\{AuditService, TokenService};
use App\Core\Exceptions\{AuthenticationException, SecurityException};
use App\Models\{User, Role, Permission};

class AuthenticationSystem implements AuthenticationInterface
{
    private MultiFactorService $mfa;
    private TokenService $tokenService;
    private AuditService $audit;
    private AccessControlList $acl;
    private array $securityConfig;

    public function __construct(
        MultiFactorService $mfa,
        TokenService $tokenService,
        AuditService $audit,
        AccessControlList $acl
    ) {
        $this->mfa = $mfa;
        $this->tokenService = $tokenService;
        $this->audit = $audit;
        $this->acl = $acl;
        $this->securityConfig = config('security');
    }

    public function authenticate(array $credentials, SecurityContext $context): AuthResult
    {
        DB::beginTransaction();
        
        try {
            // Validate login attempt
            $this->validateLoginAttempt($credentials['username']);

            // Authenticate user
            $user = $this->validateCredentials($credentials);

            // Verify MFA if enabled
            if ($user->mfa_enabled) {
                $this->verifyMFA($user, $credentials['mfa_code'] ?? null);
            }

            // Generate secure session
            $session = $this->createSecureSession($user, $context);

            // Update security state
            $this->updateSecurityState($user, $context);

            DB::commit();

            return new AuthResult($user, $session);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuthFailure($e, $credentials['username'], $context);
            throw $e;
        }
    }

    public function authorizeAction(User $user, string $action, array $resources): bool
    {
        try {
            // Check if user has required permissions
            if (!$this->acl->hasPermission($user, $action)) {
                $this->audit->logUnauthorizedAccess($user, $action);
                return false;
            }

            // Validate resource access
            foreach ($resources as $resource) {
                if (!$this->validateResourceAccess($user, $resource)) {
                    $this->audit->logResourceAccessDenied($user, $resource);
                    return false;
                }
            }

            // Check additional security constraints
            if (!$this->validateSecurityConstraints($user, $action)) {
                $this->audit->logSecurityConstraintViolation($user, $action);
                return false;
            }

            $this->audit->logSuccessfulAuthorization($user, $action);
            return true;

        } catch (\Exception $e) {
            $this->handleAuthorizationFailure($e, $user, $action);
            return false;
        }
    }

    private function validateLoginAttempt(string $username): void
    {
        $attempts = $this->getLoginAttempts($username);
        
        if ($attempts >= $this->securityConfig['max_login_attempts']) {
            $this->audit->logExcessiveLoginAttempts($username);
            throw new AuthenticationException('Account temporarily locked');
        }
    }

    private function validateCredentials(array $credentials): User
    {
        $user = User::where('username', $credentials['username'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->incrementLoginAttempts($credentials['username']);
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->status !== 'active') {
            throw new AuthenticationException('Account inactive');
        }

        return $user;
    }

    private function verifyMFA(User $user, ?string $code): void
    {
        if (!$code || !$this->mfa->verifyCode($user, $code)) {
            throw new AuthenticationException('Invalid MFA code');
        }
    }

    private function createSecureSession(User $user, SecurityContext $context): array
    {
        // Generate cryptographically secure tokens
        $accessToken = $this->tokenService->generateAccessToken($user);
        $refreshToken = $this->tokenService->generateRefreshToken($user);

        // Create session with security constraints
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => now()->addMinutes($this->securityConfig['session_lifetime']),
            'security_level' => $this->calculateSecurityLevel($user, $context)
        ];
    }

    private function updateSecurityState(User $user, SecurityContext $context): void
    {
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $context->getIpAddress(),
            'login_attempts' => 0
        ]);

        $this->audit->logSuccessfulLogin($user, $context);
    }

    private function validateResourceAccess(User $user, mixed $resource): bool
    {
        // Check resource-specific permissions
        if (!$this->acl->canAccessResource($user, $resource)) {
            return false;
        }

        // Verify resource state
        if (!$this->isResourceAccessible($resource)) {
            return false;
        }

        return true;
    }

    private function validateSecurityConstraints(User $user, string $action): bool
    {
        // Check time-based restrictions
        if (!$this->isWithinAllowedTimeWindow($action)) {
            return false;
        }

        // Verify IP restrictions
        if (!$this->isIpAllowed($user)) {
            return false;
        }

        // Check security clearance
        if (!$this->hasRequiredSecurityClearance($user, $action)) {
            return false;
        }

        return true;
    }

    private function getLoginAttempts(string $username): int
    {
        return Cache::get("login_attempts:{$username}", 0);
    }

    private function incrementLoginAttempts(string $username): void
    {
        Cache::increment("login_attempts:{$username}", 1, $this->securityConfig['lockout_duration']);
    }

    private function calculateSecurityLevel(User $user, SecurityContext $context): int
    {
        $baseLevel = $user->security_clearance;
        
        // Adjust based on authentication factors
        if ($user->mfa_enabled) {
            $baseLevel += 1;
        }

        // Adjust based on connection security
        if ($context->isSecureConnection()) {
            $baseLevel += 1;
        }

        return $baseLevel;
    }

    private function handleAuthFailure(\Exception $e, string $username, SecurityContext $context): void
    {
        $this->audit->logAuthenticationFailure($username, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($e instanceof SecurityException) {
            $this->executeSecurityProtocols($username, $context);
        }
    }

    private function handleAuthorizationFailure(\Exception $e, User $user, string $action): void
    {
        $this->audit->logAuthorizationFailure($user, $action, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
