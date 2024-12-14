namespace App\Core\Auth;

class AuthenticationManager implements AuthenticationInterface 
{
    private UserRepository $users;
    private TokenService $tokens;
    private SecurityConfig $config;
    private AuditLogger $audit;

    public function __construct(
        UserRepository $users,
        TokenService $tokens, 
        SecurityConfig $config,
        AuditLogger $audit
    ) {
        $this->users = $users;
        $this->tokens = $tokens;
        $this->config = $config;
        $this->audit = $audit;
    }

    public function authenticate(array $credentials): AuthResult
    {
        DB::beginTransaction();
        
        try {
            // Validate credentials
            if (!$this->validateCredentials($credentials)) {
                throw new AuthenticationException('Invalid credentials');
            }

            // Get user
            $user = $this->users->findByUsername($credentials['username']);
            
            if (!$user || !$this->verifyPassword($credentials['password'], $user->password)) {
                throw new AuthenticationException('Invalid username or password');
            }

            // Check account status
            if (!$user->isActive()) {
                throw new AuthenticationException('Account is inactive');
            }

            // Generate token
            $token = $this->tokens->generate([
                'user_id' => $user->id,
                'roles' => $user->roles,
                'expires' => time() + $this->config->get('token_lifetime')
            ]);

            // Log successful login
            $this->audit->logAuthentication($user->id, true);

            DB::commit();

            return new AuthResult($user, $token);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->audit->logAuthentication($credentials['username'] ?? null, false, $e->getMessage());
            throw $e;
        }
    }

    public function validateToken(string $token): TokenValidationResult
    {
        try {
            // Verify token
            $payload = $this->tokens->verify($token);
            
            // Check expiration
            if ($payload['expires'] < time()) {
                throw new TokenExpiredException();
            }

            // Get user
            $user = $this->users->find($payload['user_id']);
            
            if (!$user || !$user->isActive()) {
                throw new AuthenticationException('Invalid or inactive user');
            }

            return new TokenValidationResult($user, $payload);

        } catch (\Exception $e) {
            $this->audit->logTokenValidation($token, false, $e->getMessage());
            throw $e;
        }
    }

    public function logout(string $token): void
    {
        try {
            $payload = $this->tokens->verify($token);
            $this->tokens->revoke($token);
            $this->audit->logLogout($payload['user_id']);
        } catch (\Exception $e) {
            // Log but don't throw on logout
            $this->audit->logLogout(null, $e->getMessage());
        }
    }

    protected function validateCredentials(array $credentials): bool
    {
        return isset($credentials['username']) 
            && isset($credentials['password'])
            && strlen($credentials['password']) >= $this->config->get('min_password_length');
    }

    protected function verifyPassword(string $given, string $stored): bool
    {
        return password_verify(
            $given,
            $stored
        );
    }
}
