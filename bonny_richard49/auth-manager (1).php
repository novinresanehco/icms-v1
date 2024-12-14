<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{DB, Hash};
use App\Core\Interfaces\AuthenticationInterface;
use App\Core\Services\{SecurityManager, CacheManager};
use App\Core\Exceptions\{AuthException, ValidationException};

class AuthenticationManager implements AuthenticationInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private array $config;
    private ?array $currentUser = null;
    private array $permissions = [];
    
    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function authenticate(array $credentials, array $options = []): array 
    {
        try {
            $this->validateCredentials($credentials);
            $user = $this->verifyCredentials($credentials);
            
            if ($this->requiresMFA($user) && !isset($credentials['mfa_code'])) {
                return $this->initiateMFA($user);
            }

            if (isset($credentials['mfa_code'])) {
                $this->verifyMFA($user, $credentials['mfa_code']);
            }

            $session = $this->createSession($user, $options);
            $this->loadUserPermissions($user['id']);
            
            return array_merge($user, ['session' => $session]);
        } catch (\Exception $e) {
            $this->logFailedAttempt($credentials, $e);
            throw new AuthException('Authentication failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function validate(string $token): array 
    {
        try {
            $session = $this->validateSession($token);
            $user = $this->getUser($session['user_id']);
            
            if ($this->sessionRequiresRefresh($session)) {
                $session = $this->refreshSession($session);
            }
            
            return array_merge($user, ['session' => $session]);
        } catch (\Exception $e) {
            throw new AuthException('Session validation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function authorize(string $permission): bool 
    {
        if (!$this->currentUser) {
            return false;
        }

        return $this->hasPermission($permission);
    }

    public function logout(string $token): void 
    {
        try {
            $session = $this->validateSession($token);
            $this->terminateSession($session);
            $this->currentUser = null;
            $this->permissions = [];
        } catch (\Exception $e) {
            throw new AuthException('Logout failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function validateCredentials(array $credentials): void 
    {
        $required = ['email', 'password'];
        
        foreach ($required as $field) {
            if (!isset($credentials[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }
    }

    protected function verifyCredentials(array $credentials): array 
    {
        $user = DB::table($this->config['tables']['users'])
            ->where('email', $credentials['email'])
            ->where('active', true)
            ->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthException('Invalid credentials');
        }

        if ($this->isAccountLocked($user->id)) {
            throw new AuthException('Account is locked');
        }

        return (array)$user;
    }

    protected function requiresMFA(array $user): bool 
    {
        return $user['mfa_enabled'] ?? $this->config['mfa_required'] ?? false;
    }

    protected function initiateMFA(array $user): array 
    {
        $code = $this->generateMFACode();
        $this->storeMFACode($user['id'], $code);
        $this->sendMFACode($user['email'], $code);

        return [
            'status' => 'mfa_required',
            'user_id' => $user['id']
        ];
    }

    protected function verifyMFA(array $user, string $code): void 
    {
        $storedCode = $this->getMFACode($user['id']);
        
        if (!$storedCode || $storedCode !== $code) {
            throw new AuthException('Invalid MFA code');
        }

        $this->clearMFACode($user['id']);
    }

    protected function createSession(array $user, array $options): array 
    {
        $session = [
            'id' => $this->generateSessionId(),
            'user_id' => $user['id'],
            'token' => $this->generateToken(),
            'expires_at' => time() + ($options['duration'] ?? $this->config['session_duration'] ?? 3600),
            'created_at' => time(),
            'ip_address' => $this->getClientIp(),
            'user_agent' => $this->getUserAgent()
        ];

        DB::table($this->config['tables']['sessions'])->insert($session);
        return $session;
    }

    protected function validateSession(string $token): array 
    {
        $session = DB::table($this->config['tables']['sessions'])
            ->where('token', $token)
            ->where('expires_at', '>', time())
            ->first();

        if (!$session) {
            throw new AuthException('Invalid or expired session');
        }

        return (array)$session;
    }

    protected function refreshSession(array $session): array 
    {
        $newSession = array_merge($session, [
            'token' => $this->generateToken(),
            'expires_at' => time() + ($this->config['session_duration'] ?? 3600)
        ]);

        DB::table($this->config['tables']['sessions'])
            ->where('id', $session['id'])
            ->update($newSession);

        return $newSession;
    }

    protected function terminateSession(array $session): void 
    {
        DB::table($this->config['tables']['sessions'])
            ->where('id', $session['id'])
            ->delete();
    }

    protected function loadUserPermissions(int $userId): void 
    {
        $this->permissions = $this->cache->remember(
            "user_permissions:{$userId}",
            fn() => $this->fetchUserPermissions($userId)
        );
    }

    protected function fetchUserPermissions(int $userId): array 
    {
        return DB::table($this->config['tables']['user_permissions'])
            ->join($this->config['tables']['permissions'], 'permission_id', '=', 'id')
            ->where('user_id', $userId)
            ->pluck('name')
            ->all();
    }

    protected function hasPermission(string $permission): bool 
    {
        return in_array($permission, $this->permissions);
    }

    protected function isAccountLocked(int $userId): bool 
    {
        $attempts = DB::table($this->config['tables']['login_attempts'])
            ->where('user_id', $userId)
            ->where('created_at', '>', time() - 3600)
            ->count();

        return $attempts >= ($this->config['max_attempts'] ?? 5);
    }

    protected function generateSessionId(): string 
    {
        return bin2hex(random_bytes(32));
    }

    protected function generateToken(): string 
    {
        return bin2hex(random_bytes(64));
    }

    protected function generateMFACode(): string 
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    protected function storeMFACode(int $userId, string $code): void 
    {
        $this->cache->put(
            "mfa:{$userId}",
            $code,
            $this->config['mfa_expiry'] ?? 300
        );
    }

    protected function getMFACode(int $userId): ?string 
    {
        return $this->cache->get("mfa:{$userId}");
    }

    protected function clearMFACode(int $userId): void 
    {
        $this->cache->forget("mfa:{$userId}");
    }

    protected function logFailedAttempt(array $credentials, \Exception $e): void 
    {
        DB::table($this->config['tables']['login_attempts'])->insert([
            'email' => $credentials['email'],
            'ip_address' => $this->getClientIp(),
            'user_agent' => $this->getUserAgent(),
            'error' => $e->getMessage(),
            'created_at' => time()
        ]);
    }

    protected function getClientIp(): string 
    {
        return request()->ip();
    }

    protected function getUserAgent(): string 
    {
        return request()->userAgent() ?? '';
    }

    protected function sessionRequiresRefresh(array $session): bool 
    {
        $threshold = $this->config['refresh_threshold'] ?? 300;
        return $session['expires_at'] - time() < $threshold;
    }
}
