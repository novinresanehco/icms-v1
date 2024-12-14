<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{DB, Cache, Hash};
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Interfaces\{
    AuthManagerInterface,
    StorageInterface
};
use App\Core\Exceptions\{
    AuthException,
    SecurityException,
    ValidationException
};

class AuthManager implements AuthManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private StorageInterface $storage;
    private array $config;

    private const CACHE_PREFIX = 'auth:';
    private const TOKEN_LENGTH = 32;
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_TIME = 900;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        StorageInterface $storage,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function authenticate(array $credentials, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($credentials, $context) {
            $this->validateCredentials($credentials);
            $this->checkLockout($credentials);
            
            $user = $this->verifyCredentials($credentials);
            if (!$user) {
                $this->handleFailedLogin($credentials);
                throw new AuthException('Invalid credentials');
            }
            
            $this->verifyMfaIfRequired($user, $credentials);
            $session = $this->createSession($user, $context);
            
            $this->resetFailedAttempts($credentials);
            return $this->generateAuthResponse($user, $session);
        }, $context);
    }

    public function authorize(int $userId, string $permission, array $context): bool
    {
        return $this->security->executeSecureOperation(function() use ($userId, $permission, $context) {
            $user = $this->storage->find($userId);
            if (!$user) {
                throw new AuthException('User not found');
            }
            
            $this->validateSession($context);
            return $this->checkPermission($user, $permission);
        }, $context);
    }

    public function validateToken(string $token, array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($token, $context) {
            $session = $this->verifyToken($token);
            if (!$session) {
                throw new AuthException('Invalid token');
            }
            
            if ($this->isSessionExpired($session)) {
                $this->invalidateSession($session);
                throw new AuthException('Session expired');
            }
            
            $user = $this->storage->find($session['user_id']);
            if (!$user) {
                throw new AuthException('User not found');
            }
            
            $this->extendSession($session);
            return ['user' => $user, 'session' => $session];
        }, $context);
    }

    protected function validateCredentials(array $credentials): void
    {
        if (!isset($credentials['email']) || !isset($credentials['password'])) {
            throw new ValidationException('Missing required credentials');
        }

        if (!filter_var($credentials['email'], FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid email format');
        }

        if (strlen($credentials['password']) < 8) {
            throw new ValidationException('Invalid password format');
        }
    }

    protected function checkLockout(array $credentials): void
    {
        $key = $this->getLoginAttemptsKey($credentials);
        $attempts = Cache::get($key, 0);

        if ($attempts >= self::MAX_ATTEMPTS) {
            throw new SecurityException('Account locked due to too many attempts');
        }
    }

    protected function verifyCredentials(array $credentials): ?array
    {
        $user = $this->storage->findByEmail($credentials['email']);
        if (!$user) {
            return null;
        }

        if (!Hash::check($credentials['password'], $user['password'])) {
            return null;
        }

        return $user;
    }

    protected function verifyMfaIfRequired(array $user, array $credentials): void
    {
        if ($user['mfa_enabled'] && !$this->verifyMfaCode($user, $credentials['mfa_code'] ?? null)) {
            throw new AuthException('Invalid MFA code');
        }
    }

    protected function createSession(array $user, array $context): array
    {
        $session = [
            'id' => $this->generateSessionId(),
            'user_id' => $user['id'],
            'token' => $this->generateToken(),
            'ip' => $context['ip'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'created_at' => time(),
            'expires_at' => time() + $this->config['session_lifetime'],
            'last_activity' => time()
        ];

        $this->storage->storeSession($session);
        return $session;
    }

    protected function handleFailedLogin(array $credentials): void
    {
        $key = $this->getLoginAttemptsKey($credentials);
        $attempts = Cache::increment($key, 1, self::LOCKOUT_TIME);

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->notifySecurityTeam([
                'type' => 'account_lockout',
                'email' => $credentials['email'],
                'ip' => request()->ip()
            ]);
        }
    }

    protected function checkPermission(array $user, string $permission): bool
    {
        $roles = $this->getUserRoles($user);
        foreach ($roles as $role) {
            if ($this->roleHasPermission($role, $permission)) {
                return true;
            }
        }
        return false;
    }

    protected function verifyToken(string $token): ?array
    {
        return $this->storage->findSessionByToken($token);
    }

    protected function isSessionExpired(array $session): bool
    {
        return $session['expires_at'] < time() ||
               $session['last_activity'] + $this->config['session_timeout'] < time();
    }

    protected function extendSession(array $session): void
    {
        $session['last_activity'] = time();
        $this->storage->updateSession($session['id'], $session);
    }

    protected function invalidateSession(array $session): void
    {
        $this->storage->deleteSession($session['id']);
    }

    protected function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_LENGTH));
    }

    protected function getLoginAttemptsKey(array $credentials): string
    {
        return self::CACHE_PREFIX . 'attempts:' . hash('sha256', $credentials['email']);
    }

    protected function verifyMfaCode(array $user, ?string $code): bool
    {
        if (!$code) {
            return false;
        }

        $key = self::CACHE_PREFIX . 'mfa:' . $user['id'];
        $validCode = Cache::get($key);

        return $validCode && hash_equals($validCode, $code);
    }

    protected function resetFailedAttempts(array $credentials): void
    {
        Cache::forget($this->getLoginAttemptsKey($credentials));
    }

    protected function getUserRoles(array $user): array
    {
        return $this->storage->getUserRoles($user['id']);
    }

    protected function roleHasPermission(array $role, string $permission): bool
    {
        return in_array($permission, $role['permissions'] ?? []);
    }

    protected function generateAuthResponse(array $user, array $session): array
    {
        return [
            'user' => $this->sanitizeUser($user),
            'token' => $session['token'],
            'expires_at' => $session['expires_at']
        ];
    }

    protected function sanitizeUser(array $user): array
    {
        unset($user['password'], $user['mfa_secret']);
        return $user;
    }
}
