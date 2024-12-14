<?php

namespace App\Core\Security;

use App\Core\Exception\AuthenticationException;
use App\Core\Security\Encryption\EncryptionService;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

class AuthenticationManager implements AuthenticationInterface 
{
    private EncryptionService $encryption;
    private SessionManager $session;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        EncryptionService $encryption,
        SessionManager $session,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->encryption = $encryption;
        $this->session = $session;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function authenticate(array $credentials): AuthToken
    {
        $authId = $this->generateAuthId();

        try {
            DB::beginTransaction();

            $this->validateCredentials($credentials);
            $this->checkRateLimits($credentials);
            
            $user = $this->verifyCredentials($credentials);
            
            if (!$user) {
                $this->handleFailedAuthentication($authId, $credentials);
                throw new AuthenticationException('Invalid credentials');
            }

            $token = $this->generateAuthToken($user);
            $this->startSecureSession($token, $user);
            
            $this->logSuccessfulAuth($authId, $user->getId());

            DB::commit();
            return $token;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuthFailure($authId, $e);
            throw new AuthenticationException('Authentication failed', 0, $e);
        }
    }

    public function validateSession(string $token): bool
    {
        $sessionId = $this->generateSessionId();

        try {
            $this->validateToken($token);
            $session = $this->session->get($token);

            if (!$session) {
                $this->logInvalidSession($sessionId, $token);
                return false;
            }

            if ($this->isSessionExpired($session)) {
                $this->terminateSession($token);
                return false;
            }

            $this->refreshSession($token);
            return true;

        } catch (\Exception $e) {
            $this->handleSessionFailure($sessionId, $e);
            throw new AuthenticationException('Session validation failed', 0, $e);
        }
    }

    public function terminateSession(string $token): void
    {
        try {
            DB::beginTransaction();

            $session = $this->session->get($token);
            
            if ($session) {
                $this->session->terminate($token);
                $this->logSessionTermination($session['user_id']);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSessionFailure($token, $e);
            throw new AuthenticationException('Session termination failed', 0, $e);
        }
    }

    public function refreshMultiFactorAuth(int $userId): MFAToken
    {
        $mfaId = $this->generateMFAId();

        try {
            DB::beginTransaction();

            $user = $this->findUser($userId);
            
            if (!$user) {
                throw new AuthenticationException('User not found');
            }

            $token = $this->generateMFAToken($user);
            $this->storeMFAToken($token, $user);
            
            $this->logMFARefresh($mfaId, $userId);

            DB::commit();
            return $token;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMFAFailure($mfaId, $e);
            throw new AuthenticationException('MFA refresh failed', 0, $e);
        }
    }

    public function validateMFAToken(string $token, string $code): bool
    {
        $validationId = $this->generateValidationId();

        try {
            $mfa = $this->getMFAToken($token);
            
            if (!$mfa) {
                $this->logInvalidMFA($validationId, $token);
                return false;
            }

            if ($this->isMFAExpired($mfa)) {
                $this->invalidateMFAToken($token);
                return false;
            }

            $valid = $this->verifyMFACode($mfa, $code);
            $this->logMFAValidation($validationId, $mfa['user_id'], $valid);

            return $valid;

        } catch (\Exception $e) {
            $this->handleMFAFailure($validationId, $e);
            throw new AuthenticationException('MFA validation failed', 0, $e);
        }
    }

    private function validateCredentials(array $credentials): void
    {
        $required = ['username', 'password'];
        
        foreach ($required as $field) {
            if (!isset($credentials[$field])) {
                throw new AuthenticationException("Missing required field: {$field}");
            }
        }

        if (strlen($credentials['password']) < $this->config['min_password_length']) {
            throw new AuthenticationException('Invalid password length');
        }
    }

    private function checkRateLimits(array $credentials): void
    {
        $key = "auth_attempts:{$credentials['username']}";
        $attempts = cache()->get($key, 0);

        if ($attempts >= $this->config['max_attempts']) {
            throw new AuthenticationException('Too many authentication attempts');
        }

        cache()->increment($key);
        cache()->expire($key, $this->config['lockout_time']);
    }

    private function verifyCredentials(array $credentials): ?User
    {
        $user = User::where('username', $credentials['username'])->first();

        if (!$user) {
            return null;
        }

        if (!$this->verifyPassword($credentials['password'], $user->password)) {
            return null;
        }

        return $user;
    }

    private function generateAuthToken(User $user): AuthToken
    {
        $token = new AuthToken([
            'user_id' => $user->getId(),
            'token' => $this->encryption->generateSecureToken(),
            'expires_at' => $this->calculateTokenExpiry()
        ]);

        DB::table('auth_tokens')->insert($token->toArray());

        return $token;
    }

    private function startSecureSession(AuthToken $token, User $user): void
    {
        $this->session->start([
            'token' => $token->token,
            'user_id' => $user->getId(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'expires_at' => $token->expires_at
        ]);
    }

    private function validateToken(string $token): void
    {
        if (strlen($token) !== $this->config['token_length']) {
            throw new AuthenticationException('Invalid token format');
        }

        if (!preg_match($this->config['token_pattern'], $token)) {
            throw new AuthenticationException('Invalid token structure');
        }
    }

    private function isSessionExpired(array $session): bool
    {
        return $session['expires_at'] < now();
    }

    private function getDefaultConfig(): array
    {
        return [
            'min_password_length' => 12,
            'max_attempts' => 3,
            'lockout_time' => 900,
            'token_length' => 64,
            'token_pattern' => '/^[A-Za-z0-9]+$/',
            'session_lifetime' => 3600,
            'mfa_token_lifetime' => 300
        ];
    }

    private function handleAuthFailure(string $authId, \Exception $e): void
    {
        $this->logger->error('Authentication failed', [
            'auth_id' => $authId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleSessionFailure(string $sessionId, \Exception $e): void
    {
        $this->logger->error('Session operation failed', [
            'session_id' => $sessionId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
