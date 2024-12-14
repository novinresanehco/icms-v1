<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;
use App\Core\Exceptions\AuthenticationException;
use Illuminate\Support\Facades\Hash;

class AuthenticationManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private MfaProvider $mfaProvider;
    private AuditLogger $auditLogger;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator,
        MfaProvider $mfaProvider,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->mfaProvider = $mfaProvider;
        $this->auditLogger = $auditLogger;
    }

    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processAuthentication($credentials),
            new SecurityContext('authentication', $credentials)
        );
    }

    private function processAuthentication(array $credentials): AuthResult
    {
        // Validate credentials
        $this->validateCredentials($credentials);

        // Rate limiting check
        $this->checkRateLimit($credentials['username']);

        // Verify primary credentials
        $user = $this->verifyPrimaryCredentials($credentials);

        // MFA verification if enabled
        if ($user->hasMfaEnabled()) {
            $this->verifyMfaToken($user, $credentials['mfa_token'] ?? null);
        }

        // Generate session with strict security params
        $session = $this->createSecureSession($user);

        // Log successful authentication
        $this->auditLogger->logAuthentication($user, true);

        return new AuthResult($user, $session);
    }

    private function validateCredentials(array $credentials): void
    {
        $rules = [
            'username' => 'required|string|email',
            'password' => 'required|string|min:12',
            'mfa_token' => 'nullable|string|size:6'
        ];

        if (!$this->validator->validate($credentials, $rules)) {
            throw new AuthenticationException('Invalid credentials format');
        }
    }

    private function checkRateLimit(string $username): void
    {
        $key = "auth_attempts:{$username}";
        $attempts = $this->cache->increment($key);

        if ($attempts > 3) { // Max 3 attempts per 15 minutes
            $this->auditLogger->logRateLimit($username);
            throw new RateLimitException('Too many authentication attempts');
        }

        // Set/refresh expiry
        $this->cache->expire($key, 900); // 15 minutes
    }

    private function verifyPrimaryCredentials(array $credentials): User
    {
        $user = User::where('email', $credentials['username'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->auditLogger->logFailedAttempt($credentials['username']);
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->isLocked()) {
            $this->auditLogger->logLockedAccess($user);
            throw new AuthenticationException('Account is locked');
        }

        return $user;
    }

    private function verifyMfaToken(User $user, ?string $token): void
    {
        if (!$token || !$this->mfaProvider->verifyToken($user, $token)) {
            $this->auditLogger->logFailedMfa($user);
            throw new AuthenticationException('Invalid MFA token');
        }
    }

    private function createSecureSession(User $user): Session
    {
        $session = new Session([
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'expires_at' => now()->addMinutes(15), // 15-minute timeout
            'refresh_token' => Str::random(64)
        ]);

        $session->save();

        return $session;
    }

    public function refreshSession(string $refreshToken): Session
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processSessionRefresh($refreshToken),
            new SecurityContext('session_refresh', ['token' => $refreshToken])
        );
    }

    private function processSessionRefresh(string $refreshToken): Session
    {
        $session = Session::where('refresh_token', $refreshToken)
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) {
            throw new AuthenticationException('Invalid or expired session');
        }

        // Verify IP and user agent haven't changed
        if (!$this->validateSessionContext($session)) {
            $this->auditLogger->logSessionHijackAttempt($session);
            throw new SecurityException('Session context mismatch');
        }

        // Create new session
        $newSession = $this->createSecureSession($session->user);
        
        // Invalidate old session
        $session->delete();

        return $newSession;
    }

    private function validateSessionContext(Session $session): bool
    {
        return $session->ip_address === request()->ip() &&
               $session->user_agent === request()->userAgent();
    }

    public function logout(string $sessionId): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->processLogout($sessionId),
            new SecurityContext('logout', ['session_id' => $sessionId])
        );
    }

    private function processLogout(string $sessionId): void
    {
        $session = Session::findOrFail($sessionId);
        
        // Log logout
        $this->auditLogger->logLogout($session->user);
        
        // Invalidate session
        $session->delete();
    }
}
