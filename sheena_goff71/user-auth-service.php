namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Auth\Models\User;
use Illuminate\Support\Facades\{Hash, Event, Cache};
use App\Events\Auth\{LoginEvent, LogoutEvent, MFAEvent};

class UserAuthenticationService implements AuthenticationInterface 
{
    private SecurityManager $security;
    private UserRepository $users;
    private TokenManager $tokens;
    private MFAManager $mfa;
    private SessionManager $sessions;
    private AuditLogger $logger;
    private AuthConfig $config;

    public function __construct(
        SecurityManager $security,
        UserRepository $users,
        TokenManager $tokens,
        MFAManager $mfa,
        SessionManager $sessions,
        AuditLogger $logger,
        AuthConfig $config
    ) {
        $this->security = $security;
        $this->users = $users;
        $this->tokens = $tokens;
        $this->mfa = $mfa;
        $this->sessions = $sessions;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function authenticate(array $credentials, SecurityContext $context): AuthResult
    {
        return $this->security->executeCriticalOperation(
            new AuthenticationOperation($credentials),
            $context,
            function() use ($credentials) {
                $user = $this->validateCredentials($credentials);
                
                if ($this->mfa->isRequired($user)) {
                    return $this->initiateMultiFactorAuth($user);
                }

                return $this->completeAuthentication($user);
            }
        );
    }

    public function validateMFAToken(string $token, string $sessionId): AuthResult
    {
        return $this->security->executeCriticalOperation(
            new ValidateMFAOperation($token, $sessionId),
            new SecurityContext(['session_id' => $sessionId]),
            function() use ($token, $sessionId) {
                $session = $this->sessions->getAuthenticationSession($sessionId);
                $user = $this->users->findOrFail($session->user_id);

                if (!$this->mfa->validateToken($user, $token)) {
                    $this->logger->logFailedMFA($user, $sessionId);
                    throw new AuthenticationException('Invalid MFA token');
                }

                return $this->completeAuthentication($user);
            }
        );
    }

    public function logout(string $token, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new LogoutOperation($token),
            $context,
            function() use ($token) {
                $tokenModel = $this->tokens->find($token);
                
                if ($tokenModel) {
                    $user = $this->users->find($tokenModel->user_id);
                    $this->tokens->revoke($token);
                    $this->sessions->terminateAllSessions($user->id);
                    $this->logger->logLogout($user);
                    Event::dispatch(new LogoutEvent($user));
                }
            }
        );
    }

    public function refreshToken(string $token, SecurityContext $context): AuthResult
    {
        return $this->security->executeCriticalOperation(
            new RefreshTokenOperation($token),
            $context,
            function() use ($token) {
                $tokenModel = $this->tokens->find($token);
                
                if (!$tokenModel || $tokenModel->isExpired()) {
                    throw new AuthenticationException('Invalid or expired token');
                }

                $user = $this->users->find($tokenModel->user_id);
                return $this->issueNewToken($user);
            }
        );
    }

    private function validateCredentials(array $credentials): User
    {
        $user = $this->users->findByUsername($credentials['username']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->logger->logFailedLogin($credentials['username']);
            throw new AuthenticationException('Invalid credentials');
        }

        if ($this->isAccountLocked($user)) {
            $this->logger->logLockedAccountAttempt($user);
            throw new AuthenticationException('Account is locked');
        }

        return $user;
    }

    private function initiateMultiFactorAuth(User $user): AuthResult
    {
        $session = $this->sessions->createAuthenticationSession($user);
        $this->mfa->sendToken($user);
        
        Event::dispatch(new MFAEvent($user, 'initiated'));
        
        return new AuthResult([
            'status' => 'mfa_required',
            'session_id' => $session->id,
            'expires_in' => $this->config->getMFATokenExpiry()
        ]);
    }

    private function completeAuthentication(User $user): AuthResult
    {
        $token = $this->issueNewToken($user);
        $this->sessions->createUserSession($user, $token);
        $this->logger->logSuccessfulLogin($user);
        
        Event::dispatch(new LoginEvent($user));
        
        return new AuthResult([
            'status' => 'authenticated',
            'token' => $token->token,
            'expires_in' => $this->config->getTokenExpiry(),
            'user' => $user
        ]);
    }

    private function issueNewToken(User $user): AuthToken
    {
        $this->tokens->revokeAllForUser($user->id);
        return $this->tokens->create($user, $this->config->getTokenExpiry());
    }

    private function isAccountLocked(User $user): bool
    {
        $attempts = Cache::get("login_attempts:{$user->id}", 0);
        return $attempts >= $this->config->getMaxLoginAttempts();
    }

    private function incrementLoginAttempts(User $user): void
    {
        $attempts = Cache::increment("login_attempts:{$user->id}");
        
        if ($attempts >= $this->config->getMaxLoginAttempts()) {
            $this->lockAccount($user);
        }
    }

    private function lockAccount(User $user): void
    {
        $user->locked_at = now();
        $user->save();
        
        $this->logger->logAccountLock($user);
        Event::dispatch(new AccountLockedEvent($user));
    }

    private function resetLoginAttempts(User $user): void
    {
        Cache::forget("login_attempts:{$user->id}");
    }
}
