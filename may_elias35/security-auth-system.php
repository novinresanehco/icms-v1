<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Hash, Cache, Log};
use App\Core\Exceptions\{AuthenticationException, SecurityException};

class AuthenticationManager implements AuthenticationInterface 
{
    private UserRepository $users;
    private SessionManager $sessions;
    private SecurityConfig $config;
    private MetricsCollector $metrics;
    private AuditLogger $auditLogger;

    public function __construct(
        UserRepository $users,
        SessionManager $sessions,
        SecurityConfig $config,
        MetricsCollector $metrics,
        AuditLogger $auditLogger
    ) {
        $this->users = $users;
        $this->sessions = $sessions;
        $this->config = $config;
        $this->metrics = $metrics;
        $this->auditLogger = $auditLogger;
    }

    public function authenticate(array $credentials): AuthResult 
    {
        $startTime = microtime(true);

        try {
            // Validate rate limits
            $this->checkRateLimit($credentials['username']);

            // Validate credentials format
            $this->validateCredentials($credentials);

            // Verify user credentials
            $user = $this->verifyCredentials($credentials);

            // Generate secure session
            $session = $this->establishSession($user);

            // Log successful authentication
            $this->logSuccess($user);

            return new AuthResult([
                'user' => $user,
                'session' => $session,
                'expires_at' => $this->calculateExpiration()
            ]);

        } catch (\Exception $e) {
            $this->handleFailure($credentials['username'], $e);
            throw new AuthenticationException('Authentication failed', 0, $e);
        } finally {
            $this->recordMetrics('authenticate', microtime(true) - $startTime);
        }
    }

    public function verifySession(string $token): SessionVerification 
    {
        $startTime = microtime(true);

        try {
            // Validate token format
            $this->validateToken($token);

            // Verify session exists and is valid
            $session = $this->sessions->verify($token);

            // Check for expiration
            if ($this->isExpired($session)) {
                throw new SecurityException('Session expired');
            }

            // Extend session if needed
            $this->extendSession($session);

            return new SessionVerification([
                'user' => $session->user,
                'session' => $session,
                'expires_at' => $session->expires_at
            ]);

        } catch (\Exception $e) {
            $this->handleSessionFailure($token, $e);
            throw new SecurityException('Session verification failed', 0, $e);
        } finally {
            $this->recordMetrics('verify_session', microtime(true) - $startTime);
        }
    }

    public function invalidateSession(string $token): void 
    {
        try {
            $session = $this->sessions->find($token);
            if ($session) {
                $this->sessions->invalidate($session);
                $this->auditLogger->log('session_invalidated', [
                    'token' => $this->maskToken($token),
                    'user_id' => $session->user_id
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Session invalidation failed', [
                'token' => $this->maskToken($token),
                'error' => $e->getMessage()
            ]);
            throw new SecurityException('Session invalidation failed', 0, $e);
        }
    }

    protected function checkRateLimit(string $username): void 
    {
        $key = "auth_attempts:{$username}";
        $attempts = Cache::get($key, 0);

        if ($attempts >= $this->config->getMaxAuthAttempts()) {
            throw new SecurityException('Rate limit exceeded');
        }

        Cache::put($key, $attempts + 1, $this->config->getRateLimitDuration());
    }

    protected function validateCredentials(array $credentials): void 
    {
        $required = ['username', 'password'];
        foreach ($required as $field) {
            if (empty($credentials[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }
    }

    protected function verifyCredentials(array $credentials): User 
    {
        $user = $this->users->findByUsername($credentials['username']);
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if (!$user->isActive()) {
            throw new SecurityException('User account is inactive');
        }

        return $user;
    }

    protected function establishSession(User $user): Session 
    {
        return $this->sessions->create([
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'expires_at' => $this->calculateExpiration()
        ]);
    }

    protected function calculateExpiration(): Carbon 
    {
        return now()->addMinutes($this->config->getSessionDuration());
    }

    protected function isExpired(Session $session): bool 
    {
        return $session->expires_at->isPast();
    }

    protected function extendSession(Session $session): void 
    {
        if ($this->shouldExtendSession($session)) {
            $session->expires_at = $this->calculateExpiration();
            $session->save();
        }
    }

    protected function shouldExtendSession(Session $session): bool 
    {
        $threshold = $session->expires_at->subMinutes(
            $this->config->getSessionExtensionThreshold()
        );
        return now()->gte($threshold);
    }

    protected function logSuccess(User $user): void 
    {
        $this->auditLogger->log('authentication_success', [
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    protected function handleFailure(string $username, \Exception $e): void 
    {
        $this->auditLogger->log('authentication_failure', [
            'username' => $username,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'error' => $e->getMessage()
        ]);
    }

    protected function handleSessionFailure(string $token, \Exception $e): void 
    {
        $this->auditLogger->log('session_verification_failure', [
            'token' => $this->maskToken($token),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'error' => $e->getMessage()
        ]);
    }

    protected function maskToken(string $token): string 
    {
        return substr($token, 0, 8) . str_repeat('*', 32);
    }

    protected function recordMetrics(string $operation, float $duration): void 
    {
        $this->metrics->record("auth.{$operation}", [
            'duration' => $duration,
            'ip_address' => request()->ip(),
            'timestamp' => time()
        ]);
    }
}
