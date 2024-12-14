<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Cache, Crypt, Hash};
use App\Core\Security\{SecurityConfig, SecurityException};
use App\Core\Interfaces\TokenManagementInterface;

class TokenManager implements TokenManagementInterface
{
    private SecurityConfig $config;
    private AuditLogger $auditLogger;
    private TokenRepository $tokenRepository;
    private EncryptionService $encryption;

    public function __construct(
        SecurityConfig $config,
        AuditLogger $auditLogger,
        TokenRepository $tokenRepository,
        EncryptionService $encryption
    ) {
        $this->config = $config;
        $this->auditLogger = $auditLogger;
        $this->tokenRepository = $tokenRepository;
        $this->encryption = $encryption;
    }

    public function generateToken(User $user, string $sessionId): TokenPair
    {
        try {
            // Generate token pair
            $accessToken = $this->createAccessToken($user, $sessionId);
            $refreshToken = $this->createRefreshToken($user, $sessionId);

            // Store token metadata
            $this->storeTokenMetadata($accessToken, $refreshToken, $user, $sessionId);

            // Log token generation
            $this->auditLogger->logTokenGeneration($user->id, $sessionId);

            return new TokenPair($accessToken, $refreshToken);

        } catch (\Exception $e) {
            $this->handleTokenGenerationFailure($e, $user->id, $sessionId);
            throw new TokenGenerationException(
                'Failed to generate secure tokens',
                previous: $e
            );
        }
    }

    private function createAccessToken(User $user, string $sessionId): string
    {
        $payload = [
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'permissions' => $user->permissions,
            'type' => 'access',
            'exp' => time() + $this->config->getAccessTokenTTL(),
            'jti' => $this->generateTokenId()
        ];

        return $this->encryption->encrypt(json_encode($payload));
    }

    private function createRefreshToken(User $user, string $sessionId): string
    {
        $payload = [
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'type' => 'refresh',
            'exp' => time() + $this->config->getRefreshTokenTTL(),
            'jti' => $this->generateTokenId()
        ];

        return $this->encryption->encrypt(json_encode($payload));
    }

    public function verifyToken(string $token): array
    {
        try {
            // Decrypt and validate token
            $payload = $this->decryptAndValidateToken($token);

            // Verify token hasn't been revoked
            if ($this->isTokenRevoked($payload['jti'])) {
                throw new TokenRevokedException('Token has been revoked');
            }

            // Verify session is still valid
            if (!$this->isSessionValid($payload['session_id'])) {
                throw new InvalidSessionException('Session is no longer valid');
            }

            return $payload;

        } catch (\Exception $e) {
            $this->handleTokenValidationFailure($e, $token);
            throw new TokenValidationException(
                'Token validation failed',
                previous: $e
            );
        }
    }

    public function refreshToken(string $refreshToken): TokenPair
    {
        try {
            // Verify refresh token
            $payload = $this->verifyToken($refreshToken);
            
            if ($payload['type'] !== 'refresh') {
                throw new InvalidTokenException('Invalid token type');
            }

            // Get user and generate new token pair
            $user = User::findOrFail($payload['user_id']);
            
            // Revoke old refresh token
            $this->revokeToken($payload['jti']);
            
            // Generate new token pair
            return $this->generateToken($user, $payload['session_id']);

        } catch (\Exception $e) {
            $this->handleTokenRefreshFailure($e, $refreshToken);
            throw new TokenRefreshException(
                'Failed to refresh token',
                previous: $e
            );
        }
    }

    public function revokeToken(string $tokenId): void
    {
        try {
            $this->tokenRepository->revokeToken($tokenId);
            $this->auditLogger->logTokenRevocation($tokenId);

        } catch (\Exception $e) {
            $this->handleTokenRevocationFailure($e, $tokenId);
            throw new TokenRevocationException(
                'Failed to revoke token',
                previous: $e
            );
        }
    }

    public function revokeAllUserTokens(int $userId): void
    {
        try {
            $this->tokenRepository->revokeAllUserTokens($userId);
            $this->auditLogger->logAllTokensRevocation($userId);

        } catch (\Exception $e) {
            $this->handleTokenRevocationFailure($e, null, $userId);
            throw new TokenRevocationException(
                'Failed to revoke user tokens',
                previous: $e
            );
        }
    }

    private function decryptAndValidateToken(string $token): array
    {
        // Decrypt token
        $payload = json_decode(
            $this->encryption->decrypt($token),
            true
        );

        // Validate token structure
        if (!$this->validateTokenStructure($payload)) {
            throw new InvalidTokenException('Invalid token structure');
        }

        // Check expiration
        if ($payload['exp'] <= time()) {
            throw new TokenExpiredException('Token has expired');
        }

        return $payload;
    }

    private function validateTokenStructure(array $payload): bool
    {
        return isset(
            $payload['user_id'],
            $payload['session_id'],
            $payload['type'],
            $payload['exp'],
            $payload['jti']
        );
    }

    private function storeTokenMetadata(
        string $accessToken,
        string $refreshToken,
        User $user,
        string $sessionId
    ): void {
        $this->tokenRepository->storeTokenMetadata([
            'access_token_id' => Hash::make($accessToken),
            'refresh_token_id' => Hash::make($refreshToken),
            'user_id' => $user->id,
            'session_id' => $sessionId,
            'expires_at' => now()->addSeconds($this->config->getRefreshTokenTTL())
        ]);
    }

    private function generateTokenId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function isTokenRevoked(string $tokenId): bool
    {
        return $this->tokenRepository->isTokenRevoked($tokenId);
    }

    private function isSessionValid(string $sessionId): bool
    {
        return Cache::has('session:' . $sessionId);
    }

    private function handleTokenGenerationFailure(\Exception $e, int $userId, string $sessionId): void
    {
        $this->auditLogger->logTokenGenerationFailure($e, $userId, $sessionId);
    }

    private function handleTokenValidationFailure(\Exception $e, string $token): void
    {
        $this->auditLogger->logTokenValidationFailure($e, $token);
    }

    private function handleTokenRefreshFailure(\Exception $e, string $refreshToken): void
    {
        $this->auditLogger->logTokenRefreshFailure($e, $refreshToken);
    }

    private function handleTokenRevocationFailure(\Exception $e, ?string $tokenId = null, ?int $userId = null): void
    {
        $this->auditLogger->logTokenRevocationFailure($e, $tokenId, $userId);
    }
}
