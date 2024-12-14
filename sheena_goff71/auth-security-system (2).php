<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{DB, Cache, Hash, Event};
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\{AuthManagerInterface, TokenInterface};
use App\Core\Exceptions\{AuthException, SecurityException};

class AuthenticationManager implements AuthManagerInterface
{
    private SecurityManager $security;
    private TokenService $tokenService;
    private UserRepository $userRepository;
    private MFAService $mfaService;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityManager $security,
        TokenService $tokenService,
        UserRepository $userRepository,
        MFAService $mfaService,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->tokenService = $tokenService;
        $this->userRepository = $userRepository;
        $this->mfaService = $mfaService;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeAuthentication($credentials),
            ['action' => 'authenticate', 'attempt_id' => uniqid()]
        );
    }

    public function verifyMFA(string $sessionId, string $code): AuthResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeMFAVerification($sessionId, $code),
            ['action' => 'verify_mfa', 'session_id' => $sessionId]
        );
    }

    protected function executeAuthentication(array $credentials): AuthResult
    {
        $this->validateCredentials($credentials);
        $this->checkRateLimit($credentials['username']);

        try {
            $user = $this->userRepository->findByUsername($credentials['username']);
            
            if (!$user || !$this->verifyPassword($credentials['password'], $user->password)) {
                $this->handleFailedAttempt($credentials['username']);
                throw new AuthException('Invalid credentials');
            }

            if ($user->requires_mfa) {
                return $this->initiateMFAProcess($user);
            }

            return $this->completeAuthentication($user);

        } catch (\Exception $e) {
            $this->logAuthFailure($credentials['username'], $e);
            throw new AuthException('Authentication failed', 0, $e);
        }
    }

    protected function executeMFAVerification(string $sessionId, string $code): AuthResult
    {
        try {
            $session = $this->validateMFASession($sessionId);
            $user = $this->userRepository->find($session['user_id']);

            if (!$this->mfaService->verifyCode($user, $code)) {
                $this->handleFailedMFA($sessionId);
                throw new AuthException('Invalid MFA code');
            }

            return $this->completeAuthentication($user);

        } catch (\Exception $e) {
            $this->logMFAFailure($sessionId, $e);
            throw new AuthException('MFA verification failed', 0, $e);
        }
    }

    protected function validateCredentials(array $credentials): void
    {
        if (!$this->validator->validateCredentials($credentials)) {
            throw new AuthException('Invalid credential format');
        }
    }

    protected function checkRateLimit(string $username): void
    {
        $key = "auth_attempts:{$username}";
        $attempts = Cache::get($key, 0);

        if ($attempts >= $this->config['max_attempts']) {
            throw new SecurityException('Too many authentication attempts');
        }

        Cache::put($key, $attempts + 1, $this->config['attempt_timeout']);
    }

    protected function verifyPassword(string $input, string $hash): bool
    {
        return Hash::check($input, $hash);
    }

    protected function initiateMFAProcess(UserEntity $user): AuthResult
    {
        $sessionId = $this->mfaService->initiateSession($user);
        $this->mfaService->sendCode($user);

        return new AuthResult([
            'status' => 'mfa_required',
            'session_id' => $sessionId,
            'expires_in' => $this->config['mfa_timeout']
        ]);
    }

    protected function validateMFASession(string $sessionId): array
    {
        $session = $this->mfaService->getSession($sessionId);

        if (!$session || $session['expires_at'] < now()) {
            throw new SecurityException('Invalid or expired MFA session');
        }

        return $session;
    }

    protected function completeAuthentication(UserEntity $user): AuthResult
    {
        DB::beginTransaction();
        
        try {
            $token = $this->tokenService->createToken($user);
            $this->updateUserSecurityInfo($user);
            
            Event::dispatch(new UserAuthenticated($user));
            
            DB::commit();
            
            return new AuthResult([
                'status' => 'success',
                'user' => $user,
                'token' => $token,
                'expires_in' => $this->config['token_lifetime']
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function updateUserSecurityInfo(UserEntity $user): void
    {
        $this->userRepository->update($user->id, [
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
            'failed_attempts' => 0
        ]);

        Cache::forget("auth_attempts:{$user->username}");
    }

    protected function handleFailedAttempt(string $username): void
    {
        $key = "auth_attempts:{$username}";
        $attempts = Cache::get($key, 0);
        
        Cache::put($key, $attempts + 1, $this->config['attempt_timeout']);
        
        if ($user = $this->userRepository->findByUsername($username)) {
            $this->userRepository->incrementFailedAttempts($user->id);
        }
    }

    protected function handleFailedMFA(string $sessionId): void
    {
        $session = $this->mfaService->getSession($sessionId);
        
        if ($session) {
            $this->userRepository->incrementFailedMFA($session['user_id']);
        }
    }

    protected function logAuthFailure(string $username, \Exception $e): void
    {
        $this->security->logSecurityEvent('auth_failure', [
            'username' => $username,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'error' => $e->getMessage()
        ]);
    }

    protected function logMFAFailure(string $sessionId, \Exception $e): void
    {
        $this->security->logSecurityEvent('mfa_failure', [
            'session_id' => $sessionId,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'error' => $e->getMessage()
        ]);
    }
}
