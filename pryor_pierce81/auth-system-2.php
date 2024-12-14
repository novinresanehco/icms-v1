<?php

namespace App\Core\Auth;

class AuthenticationManager implements AuthenticationInterface 
{
    private UserRepository $users;
    private SessionManager $sessions;
    private TokenService $tokens;
    private EncryptionService $encryption;
    private SecurityLogger $logger;
    private CacheManager $cache;

    public function __construct(
        UserRepository $users,
        SessionManager $sessions,
        TokenService $tokens,
        EncryptionService $encryption,
        SecurityLogger $logger,
        CacheManager $cache
    ) {
        $this->users = $users;
        $this->sessions = $sessions;
        $this->tokens = $tokens;
        $this->encryption = $encryption;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    public function authenticate(array $credentials): AuthResult
    {
        DB::beginTransaction();
        
        try {
            $this->validateCredentials($credentials);
            
            $user = $this->verifyUser($credentials);
            
            if ($user->requiresMfa()) {
                return $this->handleMfaAuthentication($user);
            }

            $session = $this->createAuthenticatedSession($user);
            
            DB::commit();
            $this->logger->logSuccessfulLogin($user);
            
            return new AuthResult($user, $session);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuthenticationFailure($e, $credentials);
            throw $e;
        }
    }

    public function verifyToken(string $token): bool
    {
        try {
            $cacheKey = "token_verify.{$token}";
            
            return $this->cache->remember($cacheKey, function() use ($token) {
                return $this->tokens->verify($token);
            }, 300);
            
        } catch (\Exception $e) {
            $this->logger->logTokenVerificationFailure($token, $e);
            return false;
        }
    }

    public function validateSession(string $sessionId): bool
    {
        try {
            $session = $this->sessions->find($sessionId);
            
            if (!$session || $session->isExpired()) {
                return false;
            }

            if ($session->requiresRefresh()) {
                $this->refreshSession($session);
            }

            return true;
            
        } catch (\Exception $e) {
            $this->logger->logSessionValidationFailure($sessionId, $e);
            return false;
        }
    }

    public function verifyMfa(string $userId, string $code): bool
    {
        try {
            $user = $this->users->find($userId);
            
            if (!$user || !$user->hasMfaEnabled()) {
                return false;
            }

            $isValid = $this->tokens->verifyMfaCode($user, $code);
            
            if ($isValid) {
                $this->logger->logSuccessfulMfa($user);
            } else {
                $this->logger->logFailedMfa($user);
            }

            return $isValid;
            
        } catch (\Exception $e) {
            $this->logger->logMfaVerificationError($userId, $e);
            return false;
        }
    }

    public function logout(string $sessionId): void
    {
        try {
            $session = $this->sessions->find($sessionId);
            
            if ($session) {
                $this->sessions->invalidate($session);
                $this->logger->logLogout($session->getUserId());
            }
            
        } catch (\Exception $e) {
            $this->logger->logLogoutError($sessionId, $e);
        }
    }

    private function validateCredentials(array $credentials): void
    {
        $rules = [
            'email' => 'required|email',
            'password' => 'required|string|min:8'
        ];

        $validator = Validator::make($credentials, $rules);

        if ($validator->fails()) {
            throw new InvalidCredentialsException();
        }
    }

    private function verifyUser(array $credentials): User
    {
        $user = $this->users->findByEmail($credentials['email']);
        
        if (!$user || !$this->verifyPassword($user, $credentials['password'])) {
            $this->logger->logFailedLogin($credentials['email']);
            throw new InvalidCredentialsException();
        }

        if (!$user->isActive()) {
            throw new InactiveUserException();
        }

        return $user;
    }

    private function verifyPassword(User $user, string $password): bool
    {
        return $this->encryption->verifyHash($password, $user->getPasswordHash());
    }

    private function handleMfaAuthentication(User $user): AuthResult
    {
        $mfaToken = $this->tokens->generateMfaToken($user);
        $this->logger->logMfaRequired($user);
        
        return new AuthResult($user, null, $mfaToken);
    }

    private function createAuthenticatedSession(User $user): Session
    {
        $session = $this->sessions->create([
            'user_id' => $user->getId(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);

        $this->cache->put(
            "user_session.{$user->getId()}", 
            $session->getId(),
            $session->getExpirationTime()
        );

        return $session;
    }

    private function refreshSession(Session $session): void
    {
        $session->refresh();
        $this->sessions->update($session);
        $this->logger->logSessionRefresh($session->getUserId());
    }

    private function handleAuthenticationFailure(\Exception $e, array $credentials): void
    {
        $this->logger->logAuthenticationFailure($credentials['email'], $e);
        
        if ($e instanceof InvalidCredentialsException) {
            $this->handleFailedLoginAttempt($credentials['email']);
        }
    }

    private function handleFailedLoginAttempt(string $email): void
    {
        $attempts = $this->cache->increment("login_attempts.{$email}");
        
        if ($attempts >= 5) {
            $this->users->lockAccount($email);
            $this->logger->logAccountLocked($email);
            throw new AccountLockedException();
        }
    }
}

interface AuthenticationInterface 
{
    public function authenticate(array $credentials): AuthResult;
    public function verifyToken(string $token): bool;
    public function validateSession(string $sessionId): bool;
    public function verifyMfa(string $userId, string $code): bool;
    public function logout(string $sessionId): void;
}
