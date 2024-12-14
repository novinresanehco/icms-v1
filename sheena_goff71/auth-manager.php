namespace App\Core\Auth;

class AuthenticationManager implements AuthenticationInterface
{
    private SecurityManager $security;
    private UserRepository $users;
    private TokenManager $tokens;
    private SessionManager $sessions;
    private AuditLogger $logger;
    private array $config;

    public function authenticate(AuthenticationRequest $request): AuthenticationResult
    {
        return $this->security->executeCriticalOperation(
            new AuthenticationOperation($request),
            function() use ($request) {
                // Validate credentials
                $user = $this->validateCredentials($request);
                
                // Verify MFA if enabled
                if ($user->hasMfaEnabled()) {
                    $this->verifyMfaToken($user, $request->getMfaToken());
                }
                
                // Generate session
                $session = $this->createSecureSession($user);
                
                // Generate tokens
                $tokens = $this->generateTokenPair($user);
                
                $this->logger->logSuccessfulAuth([
                    'user_id' => $user->id,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);
                
                return new AuthenticationResult($user, $session, $tokens);
            }
        );
    }

    public function validateSession(string $token): SessionValidationResult
    {
        return $this->security->executeCriticalOperation(
            new SessionValidationOperation($token),
            function() use ($token) {
                $session = $this->sessions->validate($token);
                
                if (!$session->isValid()) {
                    $this->logger->logInvalidSession($token);
                    throw new InvalidSessionException();
                }
                
                if ($session->isExpired()) {
                    $this->logger->logExpiredSession($token);
                    throw new ExpiredSessionException();
                }
                
                $this->sessions->extend($session);
                
                return new SessionValidationResult(
                    $session->getUser(),
                    $session
                );
            }
        );
    }

    public function refreshToken(string $refreshToken): TokenPair
    {
        return $this->security->executeCriticalOperation(
            new TokenRefreshOperation($refreshToken),
            function() use ($refreshToken) {
                $oldTokens = $this->tokens->validate($refreshToken);
                
                if (!$oldTokens->isValid()) {
                    $this->logger->logInvalidRefreshAttempt($refreshToken);
                    throw new InvalidTokenException();
                }
                
                $user = $oldTokens->getUser();
                $newTokens = $this->generateTokenPair($user);
                
                $this->tokens->invalidate($oldTokens);
                
                $this->logger->logTokenRefresh([
                    'user_id' => $user->id,
                    'old_token' => $oldTokens->getId(),
                    'new_token' => $newTokens->getId()
                ]);
                
                return $newTokens;
            }
        );
    }

    public function logout(string $token): void
    {
        $this->security->executeCriticalOperation(
            new LogoutOperation($token),
            function() use ($token) {
                $session = $this->sessions->get($token);
                
                if ($session) {
                    $this->sessions->invalidate($session);
                    $this->tokens->invalidateAllForUser($session->getUser());
                    
                    $this->logger->logLogout([
                        'user_id' => $session->getUser()->id,
                        'session_id' => $session->getId()
                    ]);
                }
            }
        );
    }

    protected function validateCredentials(AuthenticationRequest $request): User
    {
        $user = $this->users->findByUsername($request->getUsername());
        
        if (!$user || !$this->verifyPassword($user, $request->getPassword())) {
            $this->logger->logFailedAuth([
                'username' => $request->getUsername(),
                'ip' => $request->ip(),
                'reason' => 'invalid_credentials'
            ]);
            throw new InvalidCredentialsException();
        }
        
        return $user;
    }

    protected function verifyMfaToken(User $user, string $token): void
    {
        if (!$this->tokens->verifyMfa($user, $token)) {
            $this->logger->logFailedMfa([
                'user_id' => $user->id,
                'reason' => 'invalid_token'
            ]);
            throw new InvalidMfaTokenException();
        }
    }

    protected function createSecureSession(User $user): Session
    {
        $session = $this->sessions->create([
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(
                $this->config['session_lifetime']
            )
        ]);

        $this->logger->logSessionCreated([
            'user_id' => $user->id,
            'session_id' => $session->getId()
        ]);

        return $session;
    }

    protected function generateTokenPair(User $user): TokenPair
    {
        return $this->tokens->generatePair([
            'user_id' => $user->id,
            'access_ttl' => $this->config['access_token_ttl'],
            'refresh_ttl' => $this->config['refresh_token_ttl']
        ]);
    }

    protected function verifyPassword(User $user, string $password): bool
    {
        return password_verify(
            $password,
            $user->password_hash
        );
    }
}
