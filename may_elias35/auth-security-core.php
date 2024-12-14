<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, Log};
use App\Core\Security\SecurityManagerInterface;
use App\Core\Exceptions\{AuthenticationException, SecurityException};

class AuthenticationManager implements AuthenticationInterface 
{
    private SecurityManagerInterface $security;
    private UserRepositoryInterface $users;
    private SessionManagerInterface $sessions;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        UserRepositoryInterface $users,
        SessionManagerInterface $sessions,
        array $config
    ) {
        $this->security = $security;
        $this->users = $users;
        $this->sessions = $sessions;
        $this->config = $config;
    }

    public function authenticate(array $credentials): AuthResult 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processAuthentication($credentials),
            ['context' => 'authentication', 'credentials' => $credentials]
        );
    }

    protected function processAuthentication(array $credentials): AuthResult 
    {
        // Validate credentials
        if (!$this->validateCredentials($credentials)) {
            $this->handleFailedAttempt($credentials);
            throw new AuthenticationException('Invalid credentials');
        }

        // Get user and verify status
        $user = $this->users->findByUsername($credentials['username']);
        if (!$user || !$this->verifyPassword($credentials['password'], $user)) {
            $this->handleFailedAttempt($credentials);
            throw new AuthenticationException('Invalid credentials');
        }

        // Check account status
        if (!$user->isActive()) {
            throw new AuthenticationException('Account inactive');
        }

        // Verify 2FA if enabled
        if ($user->hasTwoFactorEnabled()) {
            $this->verifyTwoFactor($credentials['two_factor_token'] ?? null, $user);
        }

        // Create new session
        $session = $this->sessions->create($user, [
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        // Clear failed attempts
        $this->clearFailedAttempts($credentials);

        return new AuthResult($user, $session);
    }

    protected function validateCredentials(array $credentials): bool 
    {
        return isset($credentials['username']) && 
               isset($credentials['password']) &&
               strlen($credentials['username']) <= 255 &&
               strlen($credentials['password']) <= 1024;
    }

    protected function verifyPassword(string $password, User $user): bool 
    {
        return Hash::check($password, $user->password);
    }

    protected function verifyTwoFactor(?string $token, User $user): void 
    {
        if (!$token || !$this->verifyTwoFactorToken($token, $user)) {
            throw new AuthenticationException('Invalid 2FA token');
        }
    }

    protected function handleFailedAttempt(array $credentials): void 
    {
        $key = 'auth.failed.' . request()->ip();
        $attempts = Cache::get($key, 0) + 1;
        
        Cache::put($key, $attempts, now()->addHours(1));

        if ($attempts >= $this->config['max_attempts']) {
            Log::warning('Maximum failed login attempts reached', [
                'ip' => request()->ip(),
                'username' => $credentials['username'] ?? 'unknown'
            ]);
            throw new SecurityException('Maximum login attempts exceeded');
        }
    }

    protected function clearFailedAttempts(array $credentials): void 
    {
        Cache::forget('auth.failed.' . request()->ip());
    }

    protected function verifyTwoFactorToken(string $token, User $user): bool 
    {
        // Implement 2FA verification (TOTP/hardware token)
        // This is a critical security feature
        return $user->verifyTwoFactorToken($token);
    }
}
