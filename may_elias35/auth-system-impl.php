namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Monitoring\MonitoringService;
use App\Core\Exceptions\AuthenticationException;
use Illuminate\Support\Facades\{Hash, Event};
use Laravel\Sanctum\NewAccessToken;

class AuthenticationService
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected MonitoringService $monitor;
    protected int $maxAttempts = 3;
    protected int $lockoutTime = 900;
    protected int $tokenExpiration = 3600;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MonitoringService $monitor
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->monitor = $monitor;
    }

    public function authenticate(array $credentials, bool $remember = false): array
    {
        $operationId = $this->monitor->startOperation('authentication');

        try {
            $this->checkLockout($credentials['email']);
            
            $user = $this->validateCredentials($credentials);
            
            if (!$user) {
                $this->handleFailedAttempt($credentials['email']);
                throw new AuthenticationException('Invalid credentials');
            }

            if ($user->requires_2fa && !isset($credentials['2fa_code'])) {
                return $this->initiate2FA($user);
            }

            if ($user->requires_2fa) {
                $this->verify2FACode($user, $credentials['2fa_code']);
            }

            $token = $this->generateAccessToken($user);
            $this->setupUserSession($user, $remember);
            
            $this->monitor->recordSecurityEvent('successful_login', [
                'user_id' => $user->id,
                'ip' => request()->ip()
            ]);

            return [
                'user' => $user,
                'token' => $token,
                'requires_password_change' => $user->requires_password_change
            ];

        } catch (\Exception $e) {
            $this->monitor->recordSecurityEvent('failed_login', [
                'email' => $credentials['email'],
                'ip' => request()->ip(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    public function logout(string $token): void
    {
        $operationId = $this->monitor->startOperation('logout');

        try {
            $tokenModel = $this->findToken($token);
            
            if (!$tokenModel) {
                throw new AuthenticationException('Invalid token');
            }

            $user = $tokenModel->tokenable;
            
            $tokenModel->delete();
            $this->clearUserSession($user);
            
            $this->monitor->recordSecurityEvent('logout', [
                'user_id' => $user->id,
                'ip' => request()->ip()
            ]);

        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    protected function validateCredentials(array $credentials): ?User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        if ($user->is_suspended || !$user->is_active) {
            throw new AuthenticationException('Account is not active');
        }

        return $user;
    }

    protected function checkLockout(string $email): void
    {
        $key = $this->getLoginAttemptsKey($email);
        $attempts = $this->cache->get($key, 0);
        
        if ($attempts >= $this->maxAttempts) {
            throw new AuthenticationException('Too many login attempts');
        }
    }

    protected function handleFailedAttempt(string $email): void
    {
        $key = $this->getLoginAttemptsKey($email);
        $attempts = $this->cache->get($key, 0) + 1;
        
        $this->cache->put($key, $attempts, $this->lockoutTime);
        
        if ($attempts >= $this->maxAttempts) {
            Event::dispatch(new AccountLockout($email));
        }
    }

    protected function initiate2FA(User $user): array
    {
        $code = $this->generate2FACode();
        
        $this->cache->put(
            $this->get2FAKey($user),
            $code,
            300 // 5 minutes
        );

        Event::dispatch(new TwoFactorCodeGenerated($user, $code));

        return [
            'status' => '2fa_required',
            'message' => 'Please enter 2FA code'
        ];
    }

    protected function verify2FACode(User $user, string $code): void
    {
        $key = $this->get2FAKey($user);
        $storedCode = $this->cache->get($key);
        
        if (!$storedCode || $storedCode !== $code) {
            throw new AuthenticationException('Invalid 2FA code');
        }

        $this->cache->forget($key);
    }

    protected function generateAccessToken(User $user): string
    {
        $token = $user->createToken('auth_token', [
            'expires_at' => now()->addSeconds($this->tokenExpiration)
        ]);

        return $token->plainTextToken;
    }

    protected function setupUserSession(User $user, bool $remember): void
    {
        session([
            'user_id' => $user->id,
            'last_activity' => time()
        ]);

        if ($remember) {
            $this->extendSessionLifetime();
        }
    }

    protected function clearUserSession(User $user): void
    {
        session()->forget(['user_id', 'last_activity']);
        session()->regenerate();
    }

    protected function getLoginAttemptsKey(string $email): string
    {
        return "login_attempts:{$email}";
    }

    protected function get2FAKey(User $user): string
    {
        return "2fa_code:{$user->id}";
    }

    protected function generate2FACode(): string
    {
        return (string) random_int(100000, 999999);
    }

    protected function extendSessionLifetime(): void
    {
        config(['session.lifetime' => 43200]); // 30 days
    }
}
