<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityContext;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\Hash;

class AuthenticationManager implements AuthenticationInterface
{
    private SecurityContext $security;
    private SystemMonitor $monitor;
    private CacheManager $cache;
    private array $config;

    private const MAX_ATTEMPTS = 3;
    private const LOCKOUT_TIME = 900; // 15 minutes

    public function __construct(
        SecurityContext $security,
        SystemMonitor $monitor,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function authenticate(array $credentials): AuthResult
    {
        $monitoringId = $this->monitor->startOperation('authentication');
        
        try {
            $this->validateCredentials($credentials);
            
            if ($this->isLocked($credentials['username'])) {
                throw new AuthLockedException('Account is locked');
            }

            $user = $this->verifyCredentials($credentials);
            
            if (!$user) {
                $this->handleFailedAttempt($credentials['username']);
                throw new AuthenticationException('Invalid credentials');
            }

            $session = $this->createSecureSession($user);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return new AuthResult($user, $session);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw $e;
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function validateSession(string $token): bool
    {
        $monitoringId = $this->monitor->startOperation('session_validation');
        
        try {
            $session = $this->getSession($token);
            
            if (!$session) {
                return false;
            }

            if ($this->isSessionExpired($session)) {
                $this->invalidateSession($token);
                return false;
            }

            if (!$this->validateSessionIntegrity($session)) {
                $this->handleSecurityBreach($session);
                return false;
            }

            $this->extendSession($token);
            
            return true;
            
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function logout(string $token): void
    {
        $monitoringId = $this->monitor->startOperation('logout');
        
        try {
            $this->invalidateSession($token);
            $this->security->clearContext();
            
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateCredentials(array $credentials): void
    {
        if (!isset($credentials['username']) || !isset($credentials['password'])) {
            throw new AuthValidationException('Invalid credentials format');
        }

        if (strlen($credentials['username']) > 100 || strlen($credentials['password']) > 100) {
            throw new AuthValidationException('Credential length exceeds limit');
        }
    }

    private function verifyCredentials(array $credentials): ?User
    {
        $user = User::where('username', $credentials['username'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        return $user;
    }

    private function createSecureSession(User $user): array
    {
        $token = $this->generateSecureToken();
        
        $session = [
            'token' => $token,
            'user_id' => $user->id,
            'created_at' => time(),
            'expires_at' => time() + $this->config['session_lifetime'],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ];

        $this->cache->set(
            $this->getSessionKey($token),
            $session,
            $this->config['session_lifetime']
        );

        return $session;
    }

    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function getSession(string $token): ?array
    {
        return $this->cache->get($this->getSessionKey($token));
    }

    private function isSessionExpired(array $session): bool
    {
        return $session['expires_at'] < time();
    }

    private function validateSessionIntegrity(array $session): bool
    {
        return $session['ip_address'] === request()->ip() &&
               $session['user_agent'] === request()->userAgent();
    }

    private function invalidateSession(string $token): void
    {
        $this->cache->delete($this->getSessionKey($token));
    }

    private function handleFailedAttempt(string $username): void
    {
        $key = $this->getAttemptKey($username);
        $attempts = $this->cache->increment($key);
        
        if ($attempts === 1) {
            $this->cache->expire($key, self::LOCKOUT_TIME);
        }

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->lockAccount($username);
        }
    }

    private function isLocked(string $username): bool
    {
        return $this->cache->exists($this->getLockKey($username));
    }

    private function lockAccount(string $username): void
    {
        $this->cache->set(
            $this->getLockKey($username),
            time(),
            self::LOCKOUT_TIME
        );
    }

    private function handleSecurityBreach(array $session): void
    {
        $this->security->handleSecurityBreach([
            'type' => 'session_integrity_violation',
            'session' => $session,
            'current_ip' => request()->ip(),
            'current_agent' => request()->userAgent()
        ]);
    }

    private function getSessionKey(string $token): string
    {
        return "session:{$token}";
    }

    private function getAttemptKey(string $username): string
    {
        return "auth_attempts:{$username}";
    }

    private function getLockKey(string $username): string
    {
        return "auth_lock:{$username}";
    }
}
