<?php
namespace App\Core\Security;

class AuthenticationService implements AuthenticationInterface 
{
    private TokenManager $tokens;
    private SessionManager $sessions;
    private UserRepository $users;
    private AuditLogger $audit;

    public function validate(SecurityContext $context): User 
    {
        DB::beginTransaction();
        try {
            // Primary authentication
            $credentials = $context->getCredentials();
            $user = $this->validateCredentials($credentials);

            // MFA validation if enabled
            if ($user->hasMfaEnabled()) {
                $this->validateMfa($user, $context->getMfaToken());
            }

            // Create new session
            $token = $this->tokens->create($user, [
                'ip' => $context->getIpAddress(),
                'device' => $context->getDeviceInfo()
            ]);

            $this->sessions->create($token, [
                'timeout' => config('auth.session_timeout'),
                'refresh' => config('auth.refresh_window')
            ]);

            DB::commit();
            return $user;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->audit->logAuthFailure($e, $context);
            throw new AuthenticationException($e->getMessage());
        }
    }

    protected function validateCredentials(array $credentials): User
    {
        $user = $this->users->findByCredentials($credentials);
        
        if (!$user || !$this->verifyPassword($credentials['password'], $user)) {
            throw new InvalidCredentialsException();
        }

        if ($user->isLocked() || !$user->isActive()) {
            throw new AccountStatusException();
        }

        return $user;
    }

    protected function validateMfa(User $user, string $token): void
    {
        if (!$this->tokens->validateMfa($user, $token)) {
            throw new InvalidMfaTokenException();
        }
    }

    protected function verifyPassword(string $password, User $user): bool 
    {
        return password_verify(
            $password,
            $user->getPassword()
        );
    }

    public function refresh(string $token): string
    {
        if (!$session = $this->sessions->find($token)) {
            throw new InvalidSessionException();
        }

        if ($session->isExpired()) {
            throw new SessionExpiredException();
        }

        return $this->tokens->refresh($token);
    }

    public function validateToken(string $token): User
    {
        if (!$session = $this->sessions->find($token)) {
            throw new InvalidSessionException();
        }

        if ($session->isExpired()) {
            throw new SessionExpiredException(); 
        }

        return $this->users->find($session->getUserId());
    }

    public function logout(string $token): void
    {
        $this->sessions->invalidate($token);
    }
}
