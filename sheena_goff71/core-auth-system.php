<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\{Hash, Cache};
use Firebase\JWT\JWT;

class AuthenticationManager
{
    private SecurityManager $security;
    private AuditLogger $audit;
    private array $config;

    public function __construct(
        SecurityManager $security,
        AuditLogger $audit,
        array $config
    ) {
        $this->security = $security;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->performAuthentication($credentials),
            ['action' => 'authenticate', 'data' => $credentials]
        );
    }

    private function performAuthentication(array $credentials): AuthResult
    {
        // Validate credentials
        if (!$this->validateCredentials($credentials)) {
            $this->audit->logFailedAttempt($credentials);
            throw new AuthenticationException('Invalid credentials');
        }

        // Rate limiting check
        if ($this->isRateLimited($credentials)) {
            $this->audit->logRateLimitExceeded($credentials);
            throw new RateLimitException('Too many attempts');
        }

        // Load and verify user
        $user = $this->verifyUser($credentials);
        if (!$user) {
            $this->audit->logFailedAttempt($credentials);
            throw new AuthenticationException('User verification failed');
        }

        // Generate secure session
        $session = $this->createSecureSession($user);

        // Log successful authentication
        $this->audit->logSuccessfulAuth($user);

        return new AuthResult(
            user: $user,
            token: $session['token'],
            expiry: $session['expiry']
        );
    }

    private function validateCredentials(array $credentials): bool
    {
        return isset($credentials['email']) &&
               isset($credentials['password']) &&
               filter_var($credentials['email'], FILTER_VALIDATE_EMAIL) &&
               strlen($credentials['password']) >= 8;
    }

    private function isRateLimited(array $credentials): bool
    {
        $key = 'auth_attempts:' . $credentials['email'];
        $attempts = Cache::get($key, 0);
        
        if ($attempts >= $this->config['max_attempts']) {
            return true;
        }

        Cache::put($key, $attempts + 1, now()->addMinutes(15));
        return false;
    }

    private function verifyUser(array $credentials): ?User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        if (!$user->is_active || $user->requires_reset) {
            throw new AccountStatusException('Account requires attention');
        }

        return $user;
    }

    private function createSecureSession(User $user): array
    {
        $expiry = now()->addMinutes($this->config['session_lifetime']);
        
        $token = JWT::encode([
            'sub' => $user->id,
            'exp' => $expiry->timestamp,
            'jti' => Str::random(32)
        ], $this->config['jwt_secret'], 'HS256');

        // Store session info
        $this->storeSession($user, $token, $expiry);

        return [
            'token' => $token,
            'expiry' => $expiry
        ];
    }

    private function storeSession(User $user, string $token, Carbon $expiry): void
    {
        DB::table('active_sessions')->insert([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiry,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    public function validateToken(string $token): ?User
    {
        try {
            // Decode and verify token
            $payload = JWT::decode($token, $this->config['jwt_secret'], ['HS256']);
            
            // Verify token hasn't been revoked
            if ($this->isTokenRevoked($token)) {
                throw new TokenException('Token has been revoked');
            }

            // Load and verify user
            $user = User::find($payload->sub);
            if (!$user || !$user->is_active) {
                throw new TokenException('Invalid user or inactive account');
            }

            return $user;

        } catch (\Exception $e) {
            $this->audit->logInvalidToken($token, $e);
            return null;
        }
    }

    private function isTokenRevoked(string $token): bool
    {
        return Cache::has('revoked_token:' . hash('sha256', $token));
    }

    public function revokeToken(string $token): void
    {
        // Add token to revocation list
        Cache::put(
            'revoked_token:' . hash('sha256', $token),
            true,
            now()->addDays(7)
        );

        // Remove from active sessions
        DB::table('active_sessions')
            ->where('token_hash', hash('sha256', $token))
            ->delete();

        $this->audit->logTokenRevocation($token);
    }
}
