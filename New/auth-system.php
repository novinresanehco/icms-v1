<?php
namespace App\Core\Auth;

class AuthenticationService implements AuthenticationInterface 
{
    private SecurityManager $security;
    private TokenManager $tokens;
    private UserRepository $users;
    private AuditLogger $audit;

    public function authenticate(array $credentials, SecurityContext $context): AuthResult 
    {
        return $this->security->executeCriticalOperation(
            new AuthenticateUserOperation($credentials),
            $context
        );
    }

    public function validateSession(string $token): SessionValidation 
    {
        return $this->security->executeCriticalOperation(
            new ValidateSessionOperation($token),
            new SecurityContext(['system'])
        );
    }

    public function logout(string $token, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new LogoutOperation($token),
            $context
        );
    }
}

class AuthenticateUserOperation extends CriticalOperation
{
    private array $credentials;
    
    public function execute(): AuthResult
    {
        $user = $this->users->findByCredentials($this->credentials);
        
        if (!$user || !$this->verifyCredentials($user, $this->credentials)) {
            $this->audit->logFailedAuth($this->credentials);
            throw new AuthenticationException('Invalid credentials');
        }
        
        // Verify MFA
        if (!$this->verifyMFA($user, $this->credentials['totp'])) {
            $this->audit->logFailedMFA($user);
            throw new MFAException('Invalid MFA code');
        }
        
        $token = $this->tokens->generate($user);
        $this->audit->logSuccessfulAuth($user);
        
        return new AuthResult($user, $token);
    }

    public function getValidationRules(): array
    {
        return [
            'username' => 'required|string',
            'password' => 'required|string', 
            'totp' => 'required|string'
        ];
    }

    public function getRequiredPermissions(): array 
    {
        return ['auth.authenticate'];
    }
}

class ValidateSessionOperation extends CriticalOperation
{
    private string $token;
    
    public function execute(): SessionValidation
    {
        $session = $this->tokens->validate($this->token);
        
        if (!$session->isValid()) {
            $this->audit->logInvalidSession($this->token);
            throw new SessionException('Invalid or expired session');
        }

        $user = $this->users->find($session->getUserId());
        
        return new SessionValidation($session, $user);
    }
}

class LogoutOperation extends CriticalOperation
{
    private string $token;
    
    public function execute(): void
    {
        $this->tokens->revoke($this->token);
        $this->audit->logLogout($this->token);
    }
}
