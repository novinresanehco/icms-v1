<?php

namespace App\Core\Auth;

use App\Core\Security\{SecurityManager, AccessControl};
use App\Core\Services\{EncryptionService, CacheManager};
use App\Core\Auth\Exceptions\{AuthenticationException, InvalidTokenException};

class AuthenticationSystem
{
    private SecurityManager $security;
    private EncryptionService $encryption;
    private CacheManager $cache;
    private AccessControl $accessControl;

    public function __construct(
        SecurityManager $security,
        EncryptionService $encryption,
        CacheManager $cache,
        AccessControl $accessControl
    ) {
        $this->security = $security;
        $this->encryption = $encryption;
        $this->cache = $cache;
        $this->accessControl = $accessControl;
    }

    public function authenticate(array $credentials): AuthToken
    {
        return DB::transaction(function() use ($credentials) {
            // Validate credentials format
            $this->validateCredentials($credentials);
            
            // Verify user and password
            $user = $this->verifyUser($credentials);
            
            // Check for required MFA
            if ($this->requiresMFA($user)) {
                $this->verifyMFAToken($credentials['mfa_token'] ?? null, $user);
            }
            
            // Generate secure session token
            $token = $this->generateSecureToken($user);
            
            // Setup session with strict controls
            $this->setupSecureSession($user, $token);
            
            return $token;
        });
    }

    private function validateCredentials(array $credentials): void
    {
        $required = ['username', 'password'];
        
        if (!array_key_exists(...$required)) {
            throw new AuthenticationException('Invalid credentials format');
        }

        if (strlen($credentials['password']) < $this->security->getMinPasswordLength()) {
            throw new AuthenticationException('Invalid password format');
        }
    }

    private function verifyUser(array $credentials): User
    {
        $user = User::where('username', $credentials['username'])->first();
        
        if (!$user || !$this->verifyPassword($credentials['password'], $user->password)) {
            // Log failed attempt
            $this->security->logFailedLogin($credentials['username']);
            
            // Check for brute force attempts
            if ($this->detectBruteForce($credentials['username'])) {
                $this->security->lockAccount($credentials['username']);
                throw new AuthenticationException('Account locked due to multiple failed attempts');
            }
            
            throw new AuthenticationException('Invalid credentials');
        }

        return $user;
    }

    private function verifyMFAToken(?string $token, User $user): void
    {
        if (!$token || !$this->verifyTOTP($token, $user->mfa_secret)) {
            throw new AuthenticationException('Invalid MFA token');
        }
    }

    private function generateSecureToken(User $user): AuthToken
    {
        $tokenValue = $this->encryption->generateSecureRandom(64);
        
        $token = new AuthToken([
            'user_id' => $user->id,
            'token' => $this->encryption->hash($tokenValue),
            'expires_at' => now()->addMinutes(config('auth.token_lifetime')),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
        
        $token->save();
        
        return $token;
    }

    private function setupSecureSession(User $user, AuthToken $token): void
    {
        // Store session data in secure cache
        $this->cache->put(
            "session:{$token->id}",
            [
                'user_id' => $user->id,
                'permissions' => $this->accessControl->getUserPermissions($user),
                'ip_address' => request()->ip(),
                'last_activity' => now()
            ],
            config('auth.session_lifetime')
        );

        // Set secure session cookie
        cookie()->queue(
            'session_token',
            $token->token,
            config('auth.session_lifetime'),
            '/',
            null,
            true,  // Secure
            true   // HttpOnly
        );
    }

    private function verifyPassword(string $input, string $hash): bool
    {
        return $this->encryption->verifyHash($input, $hash);
    }

    private function verifyTOTP(string $token, string $secret): bool
    {
        return (new TOTPValidator())->verify($token, $secret);
    }

    private function detectBruteForce(string $username): bool
    {
        $attempts = $this->cache->get("login_attempts:{$username}", 0);
        return $attempts >= config('auth.max_attempts');
    }

    public function validateSession(string $token): SessionData
    {
        $authToken = AuthToken::where('token', $this->encryption->hash($token))
            ->where('expires_at', '>', now())
            ->first();

        if (!$authToken) {
            throw new InvalidTokenException('Invalid or expired token');
        }

        $session = $this->cache->get("session:{$authToken->id}");
        
        if (!$session || 
            $session['ip_address'] !== request()->ip() || 
            $session['last_activity']->diffInMinutes(now()) > config('auth.session_timeout')
        ) {
            throw new InvalidTokenException('Session expired or invalid');
        }

        // Update last activity
        $session['last_activity'] = now();
        $this->cache->put(
            "session:{$authToken->id}",
            $session,
            config('auth.session_lifetime')
        );

        return new SessionData($session);
    }
}
