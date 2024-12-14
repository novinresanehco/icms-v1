namespace App\Core\Auth;

class AuthenticationManager implements AuthenticationInterface
{
    private SecurityManager $security;
    private UserRepository $users;
    private TokenService $tokens;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        UserRepository $users,
        TokenService $tokens,
        CacheManager $cache,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->users = $users;
        $this->tokens = $tokens;
        $this->cache = $cache;
        $this->audit = $audit;
    }

    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeCriticalOperation(new class($credentials, $this->users, $this->tokens, $this->audit) implements CriticalOperation {
            private array $credentials;
            private UserRepository $users;
            private TokenService $tokens;
            private AuditLogger $audit;

            public function __construct(array $credentials, UserRepository $users, TokenService $tokens, AuditLogger $audit)
            {
                $this->credentials = $credentials;
                $this->users = $users;
                $this->tokens = $tokens;
                $this->audit = $audit;
            }

            public function execute(): OperationResult
            {
                $user = $this->users->findByUsername($this->credentials['username']);
                
                if (!$user || !$this->verifyPassword($user)) {
                    $this->audit->logFailedLogin($this->credentials['username']);
                    throw new AuthenticationException('Invalid credentials');
                }

                if ($user->requiresMfa()) {
                    return new OperationResult(new MfaRequiredResult($user));
                }

                $token = $this->tokens->generate($user);
                $this->audit->logSuccessfulLogin($user);

                return new OperationResult(new AuthResult($user, $token));
            }

            private function verifyPassword(User $user): bool
            {
                return password_verify(
                    $this->credentials['password'],
                    $user->password_hash
                );
            }

            public function getValidationRules(): array
            {
                return [
                    'username' => 'required|string',
                    'password' => 'required|string|min:8'
                ];
            }

            public function getData(): array
            {
                return ['username' => $this->credentials['username']];
            }

            public function getRequiredPermissions(): array
            {
                return [];
            }

            public function getRateLimitKey(): string
            {
                return 'auth:login:' . $this->credentials['username'];
            }
        });
    }

    public function validateMfa(string $token, string $code): AuthResult
    {
        return $this->security->executeCriticalOperation(new class($token, $code, $this->tokens, $this->users) implements CriticalOperation {
            private string $token;
            private string $code;
            private TokenService $tokens;
            private UserRepository $users;

            public function __construct(string $token, string $code, TokenService $tokens, UserRepository $users)
            {
                $this->token = $token;
                $this->code = $code;
                $this->tokens = $tokens;
                $this->users = $users;
            }

            public function execute(): OperationResult
            {
                $tempToken = $this->tokens->verify($this->token);
                $user = $this->users->find($tempToken->user_id);

                if (!$user->verifyMfaCode($this->code)) {
                    throw new MfaException('Invalid MFA code');
                }

                $token = $this->tokens->generate($user);
                return new OperationResult(new AuthResult($user, $token));
            }

            public function getValidationRules(): array
            {
                return [
                    'token' => 'required|string',
                    'code' => 'required|string|size:6'
                ];
            }

            public function getData(): array
            {
                return [
                    'token' => $this->token,
                    'code_length' => strlen($this->code)
                ];
            }

            public function getRequiredPermissions(): array
            {
                return [];
            }

            public function getRateLimitKey(): string
            {
                return 'auth:mfa:' . $this->token;
            }
        });
    }

    public function validateSession(string $token): bool
    {
        return $this->security->executeCriticalOperation(new class($token, $this->tokens, $this->users) implements CriticalOperation {
            private string $token;
            private TokenService $tokens;
            private UserRepository $users;

            public function __construct(string $token, TokenService $tokens, UserRepository $users)
            {
                $this->token = $token;
                $this->tokens = $tokens;
                $this->users = $users;
            }

            public function execute(): OperationResult
            {
                $tokenData = $this->tokens->verify($this->token);
                $user = $this->users->find($tokenData->user_id);

                if (!$user->isActive()) {
                    throw new AuthenticationException('User account is inactive');
                }

                return new OperationResult(true);
            }

            public function getValidationRules(): array
            {
                return ['token' => 'required|string'];
            }

            public function getData(): array
            {
                return ['token' => $this->token];
            }

            public function getRequiredPermissions(): array
            {
                return [];
            }

            public function getRateLimitKey(): string
            {
                return 'auth:validate:' . $this->token;
            }
        });
    }

    public function invalidateSession(string $token): void
    {
        $this->security->executeCriticalOperation(new class($token, $this->tokens, $this->audit) implements CriticalOperation {
            private string $token;
            private TokenService $tokens;
            private AuditLogger $audit;

            public function __construct(string $token, TokenService $tokens, AuditLogger $audit)
            {
                $this->token = $token;
                $this->tokens = $tokens;
                $this->audit = $audit;
            }

            public function execute(): OperationResult
            {
                $tokenData = $this->tokens->verify($this->token);
                $this->tokens->revoke($this->token);
                $this->audit->logLogout($tokenData->user_id);
                
                return new OperationResult(true);
            }

            public function getValidationRules(): array
            {
                return ['token' => 'required|string'];
            }

            public function getData(): array
            {
                return ['token' => $this->token];
            }

            public function getRequiredPermissions(): array
            {
                return [];
            }

            public function getRateLimitKey(): string
            {
                return 'auth:logout:' . $this->token;
            }
        });
    }
}
