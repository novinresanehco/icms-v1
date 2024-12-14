namespace App\Core\Security;

use App\Core\Services\ValidationService;
use App\Core\Services\MetricsCollector;
use App\Core\Cache\CacheManager;
use App\Core\Events\EventManager;
use Illuminate\Support\Facades\Hash;
use Firebase\JWT\JWT;

class AuthenticationService
{
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private CacheManager $cache;
    private EventManager $events;
    private array $config;

    public function __construct(
        ValidationService $validator,
        MetricsCollector $metrics,
        CacheManager $cache,
        EventManager $events,
        array $config
    ) {
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->cache = $cache;
        $this->events = $events;
        $this->config = $config;
    }

    public function authenticate(array $credentials): array
    {
        $this->validateCredentials($credentials);
        
        try {
            $user = $this->verifyCredentials($credentials);
            
            if (!$user) {
                $this->handleFailedAuthentication($credentials);
                throw new AuthenticationException('Invalid credentials');
            }

            $this->validateMfaIfRequired($user, $credentials);
            
            $token = $this->generateSecureToken($user);
            $session = $this->createSecureSession($user, $token);
            
            $this->events->dispatch('user.authenticated', [
                'user_id' => $user['id'],
                'session_id' => $session['id']
            ]);

            return [
                'token' => $token,
                'session' => $session,
                'user' => $this->sanitizeUserData($user)
            ];

        } catch (\Throwable $e) {
            $this->metrics->incrementAuthErrors($e->getCode());
            throw $e;
        }
    }

    public function validateSession(string $token): array
    {
        try {
            $session = $this->decodeToken($token);
            
            if (!$this->isValidSession($session)) {
                throw new AuthenticationException('Invalid session');
            }

            if ($this->isSessionExpired($session)) {
                throw new AuthenticationException('Session expired');
            }

            $user = $this->getUserFromSession($session);
            
            if (!$user) {
                throw new AuthenticationException('User not found');
            }

            $this->refreshSession($session);
            
            return [
                'user' => $this->sanitizeUserData($user),
                'session' => $session
            ];

        } catch (\Throwable $e) {
            $this->metrics->incrementSessionErrors($e->getCode());
            throw $e;
        }
    }

    public function authorize(array $user, string $permission, array $context = []): bool
    {
        try {
            if (!$this->isUserActive($user)) {
                return false;
            }

            if ($this->isUserBlocked($user)) {
                throw new AuthorizationException('User is blocked');
            }

            if (!$this->hasPermission($user, $permission, $context)) {
                $this->logUnauthorizedAccess($user, $permission, $context);
                return false;
            }

            $this->logAuthorizedAccess($user, $permission, $context);
            return true;

        } catch (\Throwable $e) {
            $this->metrics->incrementAuthzErrors($e->getCode());
            throw $e;
        }
    }

    protected function validateCredentials(array $credentials): void
    {
        if (!$this->validator->validate($credentials, [
            'email' => 'required|email',
            'password' => 'required|min:12'
        ])) {
            throw new ValidationException('Invalid credentials format');
        }
    }

    protected function verifyCredentials(array $credentials): ?array
    {
        $user = $this->findUserByEmail($credentials['email']);
        
        if (!$user || !$this->verifyPassword($credentials['password'], $user['password'])) {
            return null;
        }

        return $user;
    }

    protected function validateMfaIfRequired(array $user, array $credentials): void
    {
        if ($this->isMfaRequired($user)) {
            $this->verifyMfaToken($user, $credentials['mfa_token'] ?? null);
        }
    }

    protected function generateSecureToken(array $user): string
    {
        $payload = [
            'user_id' => $user['id'],
            'roles' => $user['roles'],
            'exp' => time() + $this->config['token_lifetime'],
            'jti' => $this->generateUniqueId()
        ];

        return JWT::encode($payload, $this->config['jwt_secret'], 'HS512');
    }

    protected function createSecureSession(array $user, string $token): array
    {
        $session = [
            'id' => $this->generateUniqueId(),
            'user_id' => $user['id'],
            'token' => $token,
            'created_at' => time(),
            'expires_at' => time() + $this->config['session_lifetime'],
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ];

        $this->cache->put(
            "session:{$session['id']}", 
            $session,
            $this->config['session_lifetime']
        );

        return $session;
    }

    protected function isValidSession(array $session): bool
    {
        return isset($session['id'], $session['user_id'], $session['token']) &&
               $this->validateSessionSignature($session);
    }

    protected function isSessionExpired(array $session): bool
    {
        return $session['expires_at'] < time();
    }

    protected function refreshSession(array $session): void
    {
        $session['expires_at'] = time() + $this->config['session_lifetime'];
        
        $this->cache->put(
            "session:{$session['id']}", 
            $session,
            $this->config['session_lifetime']
        );
    }

    protected function validateSessionSignature(array $session): bool
    {
        $signature = hash_hmac('sha256', json_encode([
            'id' => $session['id'],
            'user_id' => $session['user_id'],
            'created_at' => $session['created_at']
        ]), $this->config['session_secret']);

        return hash_equals($signature, $session['signature'] ?? '');
    }

    protected function hasPermission(array $user, string $permission, array $context): bool
    {
        foreach ($user['roles'] as $role) {
            if ($this->roleHasPermission($role, $permission, $context)) {
                return true;
            }
        }
        return false;
    }

    protected function roleHasPermission(string $role, string $permission, array $context): bool
    {
        $permissions = $this->cache->remember(
            "role:permissions:{$role}",
            3600,
            fn() => $this->loadRolePermissions($role)
        );

        return in_array($permission, $permissions) &&
               $this->validatePermissionContext($role, $permission, $context);
    }

    protected function validatePermissionContext(string $role, string $permission, array $context): bool
    {
        $validator = $this->config['permission_validators'][$permission] ?? null;
        return !$validator || $validator($role, $context);
    }

    protected function sanitizeUserData(array $user): array
    {
        unset($user['password'], $user['reset_token'], $user['remember_token']);
        return $user;
    }

    protected function generateUniqueId(): string
    {
        return hash('sha256', uniqid('', true));
    }

    protected function handleFailedAuthentication(array $credentials): void
    {
        $this->metrics->incrementFailedLogins($credentials['email']);
        
        if ($this->exceedsFailureThreshold($credentials['email'])) {
            $this->blockUser($credentials['email']);
            throw new AuthenticationException('Account temporarily blocked');
        }
    }
}
