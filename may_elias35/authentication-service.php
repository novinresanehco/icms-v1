<?php

namespace App\Core\Security;

use App\Core\Monitoring\SystemMonitor;
use App\Core\Exceptions\AuthenticationException;
use Firebase\JWT\JWT;

class AuthenticationService implements AuthenticationInterface
{
    private SystemMonitor $monitor;
    private EncryptionService $encryption;
    private array $config;

    public function __construct(
        SystemMonitor $monitor,
        EncryptionService $encryption,
        array $config
    ) {
        $this->monitor = $monitor;
        $this->encryption = $encryption;
        $this->config = $config;
    }

    public function authenticate(array $credentials): AuthToken
    {
        $monitoringId = $this->monitor->startOperation('authentication');
        
        try {
            $this->validateCredentials($credentials);
            
            $user = $this->verifyUser($credentials);
            
            $this->validateUserStatus($user);
            $this->validateMultiFactor($user, $credentials);
            
            $token = $this->generateToken($user);
            
            $this->recordAuthentication($user);
            $this->monitor->recordSuccess($monitoringId);
            
            return $token;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new AuthenticationException('Authentication failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function validateToken(string $token): bool
    {
        $monitoringId = $this->monitor->startOperation('token_validation');
        
        try {
            $payload = $this->decodeToken($token);
            
            $this->validateTokenClaims($payload);
            $this->validateTokenStatus($payload);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return true;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            return false;
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function refreshToken(string $token): AuthToken
    {
        $monitoringId = $this->monitor->startOperation('token_refresh');
        
        try {
            $payload = $this->decodeToken($token);
            
            $this->validateRefreshEligibility($payload);
            
            $user = $this->getUserFromPayload($payload);
            $newToken = $this->generateToken($user);
            
            $this->invalidateToken($token);
            $this->monitor->recordSuccess($monitoringId);
            
            return $newToken;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new AuthenticationException('Token refresh failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateCredentials(array $credentials): void
    {
        if (!isset($credentials['username']) || !isset($credentials['password'])) {
            throw new AuthenticationException('Invalid credentials format');
        }
    }

    private function verifyUser(array $credentials): User
    {
        $user = User::where('username', $credentials['username'])->first();
        
        if (!$user || !$this->verifyPassword($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }
        
        return $user;
    }

    private function validateUserStatus(User $user): void
    {
        if (!$user->is_active) {
            throw new AuthenticationException('User account is inactive');
        }

        if ($user->is_locked) {
            throw new AuthenticationException('User account is locked');
        }
    }

    private function validateMultiFactor(User $user, array $credentials): void
    {
        if ($user->mfa_enabled) {
            if (!isset($credentials['mfa_code'])) {
                throw new AuthenticationException('MFA code required');
            }
            
            if (!$this->verifyMFACode($user, $credentials['mfa_code'])) {
                throw new AuthenticationException('Invalid MFA code');
            }
        }
    }

    private function generateToken(User $user): AuthToken
    {
        $payload = [
            'sub' => $user->id,
            'iat' => time(),
            'exp' => time() + $this->config['token_lifetime'],
            'jti' => $this->generateTokenId(),
            'roles' => $user->roles->pluck('name')->toArray()
        ];

        $token = JWT::encode(
            $payload,
            $this->config['jwt_key'],
            $this->config['jwt_algorithm']
        );

        return new AuthToken($token, $payload);
    }

    private function decodeToken(string $token): array
    {
        try {
            return (array) JWT::decode(
                $token,
                $this->config['jwt_key'],
                [$this->config['jwt_algorithm']]
            );
        } catch (\Exception $e) {
            throw new AuthenticationException('Invalid token');
        }
    }

    private function validateTokenClaims(array $payload): void
    {
        if (!isset($payload['sub']) || !isset($payload['exp']) || !isset($payload['jti'])) {
            throw new AuthenticationException('Invalid token claims');
        }

        if ($payload['exp'] < time()) {
            throw new AuthenticationException('Token has expired');
        }
    }

    private function validateTokenStatus(array $payload): void
    {
        if ($this->isTokenRevoked($payload['jti'])) {
            throw new AuthenticationException('Token has been revoked');
        }
    }

    private function validateRefreshEligibility(array $payload): void
    {
        if ($payload['exp'] < (time() - $this->config['refresh_grace_period'])) {
            throw new AuthenticationException('Token refresh period expired');
        }
    }

    private function verifyPassword(string $input, string $hash): bool
    {
        return password_verify($input, $hash);
    }

    private function verifyMFACode(User $user, string $code): bool
    {
        // Implementation depends on MFA mechanism (TOTP, SMS, etc.)
        return true;
    }

    private function generateTokenId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function recordAuthentication(User $user): void
    {
        AuthenticationLog::create([
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now()
        ]);
    }

    private function invalidateToken(string $token): void
    {
        $payload = $this->decodeToken($token);
        
        RevokedToken::create([
            'jti' => $payload['jti'],
            'expires_at' => date('Y-m-d H:i:s', $payload['exp'])
        ]);
    }

    private function isTokenRevoked(string $jti): bool
    {
        return RevokedToken::where('jti', $jti)->exists();
    }

    private function getUserFromPayload(array $payload): User
    {
        return User::findOrFail($payload['sub']);
    }
}
