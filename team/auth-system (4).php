<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Auth\Events\AuthEvent;
use App\Core\Auth\DTOs\{LoginData, UserData};
use App\Core\Exceptions\{AuthException, ValidationException};

class AuthenticationManager implements AuthenticationInterface
{
    private SecurityManager $security;
    private UserRepository $users;
    private RoleManager $roles;
    private TokenManager $tokens;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function authenticate(array $credentials, array $options = []): AuthResult
    {
        return $this->security->executeCriticalOperation(
            new AuthenticationOperation($credentials),
            new SecurityContext(['ip' => request()->ip()]),
            function() use ($credentials, $options) {
                try {
                    $validated = $this->validator->validate($credentials);
                    
                    $user = $this->users->findByUsername($validated['username']);
                    
                    if (!$user || !Hash::check($validated['password'], $user->password)) {
                        $this->handleFailedLogin($validated['username']);
                        throw new AuthException('Invalid credentials');
                    }

                    if ($this->isLocked($user)) {
                        throw new AuthException('Account is locked');
                    }

                    if ($options['mfa'] ?? false) {
                        $this->verifyMfaToken($user, $validated['mfa_token'] ?? null);
                    }

                    $token = $this->tokens->create($user, [
                        'ip' => request()->ip(),
                        'device' => request()->userAgent()
                    ]);

                    $this->auditLogger->logSuccessfulLogin($user);
                    event(new AuthEvent(AuthEvent::LOGIN_SUCCESS, $user));

                    return new AuthResult($user, $token);
                } catch (\Exception $e) {
                    $this->auditLogger->logFailedAuthentication($credentials, $e);
                    throw new AuthException('Authentication failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    public function authorize(int $userId, string $permission, array $context = []): bool
    {
        return $this->security->executeCriticalOperation(
            new AuthorizationOperation($userId, $permission),
            new SecurityContext($context),
            function() use ($userId, $permission) {
                try {
                    $user = $this->users->findOrFail($userId);
                    
                    if (!$this->roles->hasPermission($user->role, $permission)) {
                        $this->auditLogger->logUnauthorizedAccess($user, $permission);
                        return false;
                    }

                    $this->auditLogger->logAuthorizedAccess($user, $permission);
                    return true;
                } catch (\Exception $e) {
                    $this->auditLogger->logAuthorizationError($userId, $permission, $e);
                    throw new AuthException('Authorization failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    public function validateToken(string $token): UserData
    {
        return $this->security->executeCriticalOperation(
            new TokenValidationOperation($token),
            new SecurityContext(['token' => $token]),
            function() use ($token) {
                try {
                    $decoded = $this->tokens->decode($token);
                    
                    if (!$decoded || $this->tokens->isExpired($decoded)) {
                        throw new AuthException('Invalid or expired token');
                    }

                    $user = $this->users->findOrFail($decoded['user_id']);
                    
                    if ($this->isLocked($user)) {
                        throw new AuthException('Account is locked');
                    }

                    return new UserData($user);
                } catch (\Exception $e) {
                    $this->auditLogger->logTokenValidationFailure($token, $e);
                    throw new AuthException('Token validation failed: ' . $e->getMessage(), 0, $e);
                }
            }
        );
    }

    protected function handleFailedLogin(string $username): void
    {
        $key = "login_attempts:{$username}";
        $attempts = Cache::get($key, 0) + 1;
        
        Cache::put($key, $attempts, now()->addMinutes(30));
        
        if ($attempts >= 5) {
            $this->users->lockAccount($username);
            $this->auditLogger->logAccountLocked($username);
            throw new AuthException('Account locked due to multiple failed attempts');
        }
    }

    protected function isLocked(User $user): bool
    {
        return $user->locked_until && $user->locked_until->isFuture();
    }

    protected function verifyMfaToken(User $user, ?string $token): void
    {
        if (!$token || !$this->tokens->verifyMfaToken($user, $token)) {
            throw new AuthException('Invalid MFA token');
        }
    }
}
