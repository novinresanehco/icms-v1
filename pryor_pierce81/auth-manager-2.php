<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\AuthenticationException;
use App\Core\Audit\AuditManagerInterface;
use Psr\Log\LoggerInterface;

class AuthenticationManager implements AuthenticationManagerInterface
{
    private SecurityManagerInterface $security;
    private AuditManagerInterface $audit;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        AuditManagerInterface $audit,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->audit = $audit;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function authenticate(array $credentials): AuthToken
    {
        $authId = $this->generateAuthId();

        try {
            DB::beginTransaction();

            $this->validateCredentials($credentials);
            $this->checkSecurityConstraints($credentials);

            $user = $this->verifyUser($credentials);
            $this->validateUserStatus($user);

            $token = $this->generateSecureToken($user);
            $this->storeTokenData($token);

            $this->audit->logAuthentication($authId, $user);

            DB::commit();
            return $token;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuthFailure($authId, $credentials, $e);
            throw new AuthenticationException('Authentication failed', 0, $e);
        }
    }

    public function validateToken(AuthToken $token): bool
    {
        $validationId = $this->generateValidationId();

        try {
            $this->validateTokenStructure($token);
            $this->validateTokenExpiry($token);
            $this->validateTokenSignature($token);
            $this->validateTokenPermissions($token);

            $this->audit->logTokenValidation($validationId, $token);

            return true;

        } catch (\Exception $e) {
            $this->handleTokenFailure($validationId, $token, $e);
            throw new AuthenticationException('Token validation failed', 0, $e);
        }
    }

    public function revokeToken(AuthToken $token): void
    {
        $revocationId = $this->generateRevocationId();

        try {
            DB::beginTransaction();

            $this->validateTokenForRevocation($token);
            $this->invalidateToken($token);
            $this->removeTokenData($token);

            $this->audit->logTokenRevocation($revocationId, $token);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRevocationFailure($revocationId, $token, $e);
            throw new AuthenticationException('Token revocation failed', 0, $e);
        }
    }

    private function validateCredentials(array $credentials): void
    {
        if (!isset($credentials['username'], $credentials['password'])) {
            throw new AuthenticationException('Invalid credentials format');
        }

        if (strlen($credentials['username']) < $this->config['min_username_length']) {
            throw new AuthenticationException('Invalid username length');
        }

        if (strlen($credentials['password']) < $this->config['min_password_length']) {
            throw new AuthenticationException('Invalid password length');
        }
    }

    private function checkSecurityConstraints(array $credentials): void
    {
        if ($this->isRateLimited($credentials['username'])) {
            throw new AuthenticationException('Rate limit exceeded');
        }

        if ($this->isBlacklisted($credentials['username'])) {
            throw new AuthenticationException('Access denied');
        }

        $this->security->validateSecureOperation('auth:attempt', [
            'username' => $credentials['username']
        ]);
    }

    private function verifyUser(array $credentials): User
    {
        $user = $this->findUser($credentials['username']);

        if (!$user || !$this->verifyPassword($credentials['password'], $user->password)) {
            $this->incrementFailedAttempts($credentials['username']);
            throw new AuthenticationException('Invalid credentials');
        }

        return $user;
    }

    private function validateUserStatus(User $user): void
    {
        if (!$user->isActive()) {
            throw new AuthenticationException('User account is not active');
        }

        if ($user->isLocked()) {
            throw new AuthenticationException('User account is locked');
        }

        if ($user->requiresPasswordChange()) {
            throw new AuthenticationException('Password change required');
        }
    }

    private function generateSecureToken(User $user): AuthToken
    {
        $tokenData = [
            'user_id' => $user->id,
            'issued_at' => time(),
            'expires_at' => time() + $this->config['token_lifetime'],
            'permissions' => $user->getPermissions()
        ];

        $token = new AuthToken(
            $tokenData,
            $this->generateTokenSignature($tokenData)
        );

        return $token;
    }

    private function handleAuthFailure(string $authId, array $credentials, \Exception $e): void
    {
        $this->logger->warning('Authentication failure', [
            'auth_id' => $authId,
            'username' => $credentials['username'],
            'error' => $e->getMessage()
        ]);

        $this->audit->logAuthFailure($authId, $credentials['username'], $e);
    }

    private function getDefaultConfig(): array
    {
        return [
            'token_lifetime' => 3600,
            'min_username_length' => 3,
            'min_password_length' => 8,
            'max_attempts' => 5,
            'lockout_duration' => 900,
            'rate_limit' => [
                'attempts' => 10,
                'period' => 300
            ]
        ];
    }
}
