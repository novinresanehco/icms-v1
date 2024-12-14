<?php

namespace App\Core\Security\Auth;

use App\Core\Security\Encryption\EncryptionService;
use App\Core\Security\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\Hash;

class AuthenticationManager implements AuthenticationInterface 
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private CacheManager $cache;
    private array $config;

    private const AUTH_CACHE_PREFIX = 'auth_token:';
    private const MAX_FAILED_ATTEMPTS = 3;
    private const LOCKOUT_TIME = 900; // 15 minutes

    public function authenticate(array $credentials): AuthResult 
    {
        DB::beginTransaction();
        
        try {
            // Validate credentials format
            $this->validator->validateCredentials($credentials);

            // Check rate limiting
            $this->checkRateLimit($credentials['identifier']);

            // Verify credentials
            $user = $this->verifyCredentials($credentials);
            
            if (!$user) {
                $this->handleFailedAttempt($credentials['identifier']);
                throw new AuthenticationException('Invalid credentials');
            }

            // Generate secure token
            $token = $this->generateSecureToken($user);

            // Store token with strict expiration
            $this->storeToken($token, $user);

            // Log successful authentication
            $this->auditLogger->logAuthentication($user->id, true);

            DB::commit();

            return new AuthResult($token, $user);

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->auditLogger->logAuthFailure($credentials['identifier'], $e);
            throw $e;
        }
    }

    public function validateToken(string $token): AuthValidationResult 
    {
        try {
            // Check token format
            if (!$this->validator->validateTokenFormat($token)) {
                throw new InvalidTokenException('Invalid token format');
            }

            // Verify token in cache
            $userData = $this->cache->get(self::AUTH_CACHE_PREFIX . $token);
            
            if (!$userData) {
                throw new TokenExpiredException('Token expired or invalid');
            }

            // Verify token integrity
            if (!$this->encryption->verifyTokenIntegrity($token, $userData)) {
                throw new TokenCompromisedException('Token integrity check failed');
            }

            // Refresh token if needed
            if ($this->shouldRefreshToken($userData)) {
                $token = $this->refreshToken($token, $userData);
            }

            return new AuthValidationResult($token, $userData);

        } catch (\Throwable $e) {
            $this->auditLogger->logTokenValidationFailure($token, $e);
            throw $e;
        }
    }

    private function verifyCredentials(array $credentials): ?User 
    {
        $user = User::where('email', $credentials['identifier'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        if ($user->requires_2fa) {
            $this->verify2FA($user, $credentials['2fa_code'] ?? null);
        }

        return $user;
    }

    private function generateSecureToken(User $user): string 
    {
        $entropy = random_bytes(32);
        $timestamp = time();
        $userData = json_encode([
            'user_id' => $user->id,
            'timestamp' => $timestamp
        ]);

        return $this->encryption->encryptToken(
            base64_encode($entropy) . '.' . $userData
        );
    }

    private function storeToken(string $token, User $user): void 
    {
        $this->cache->put(
            self::AUTH_CACHE_PREFIX . $token,
            [
                'user_id' => $user->id,
                'created_at' => time(),
                'last_activity' => time()
            ],
            $this->config['token_ttl'] ?? 3600
        );
    }

    private function checkRateLimit(string $identifier): void 
    {
        $attempts = $this->cache->get('auth_attempts:' . $identifier, 0);
        
        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            throw new RateLimitException('Too many failed attempts');
        }
    }

    private function handleFailedAttempt(string $identifier): void 
    {
        $attempts = $this->cache->increment('auth_attempts:' . $identifier);
        
        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $this->cache->put(
                'auth_lockout:' . $identifier,
                time(),
                self::LOCKOUT_TIME
            );
        }
    }

    private function verify2FA(User $user, ?string $code): void 
    {
        if (!$code || !$this->twoFactorService->verifyCode($user, $code)) {
            throw new TwoFactorRequiredException('Valid 2FA code required');
        }
    }

    private function shouldRefreshToken(array $userData): bool 
    {
        $refreshTime = $this->config['token_refresh_time'] ?? 1800;
        return (time() - $userData['last_activity']) > $refreshTime;
    }

    private function refreshToken(string $oldToken, array $userData): string 
    {
        $newToken = $this->generateSecureToken(User::find($userData['user_id']));
        
        DB::transaction(function() use ($oldToken, $newToken, $userData) {
            $this->storeToken($newToken, $userData);
            $this->cache->delete(self::AUTH_CACHE_PREFIX . $oldToken);
        });

        return $newToken;
    }
}
