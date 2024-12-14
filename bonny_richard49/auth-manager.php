<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Interfaces\AuthenticationInterface;
use Illuminate\Support\Facades\{Hash, Cache, DB};

class AuthenticationManager implements AuthenticationInterface
{
    private SecurityManager $security;
    private SessionManager $sessions;
    private MFAProvider $mfa;
    private AuditLogger $audit;
    private RateLimiter $limiter;
    
    public function authenticate(array $credentials): AuthResult
    {
        return $this->security->executeCriticalOperation(
            new AuthenticationOperation($credentials, $this->mfa),
            new SecurityContext([
                'operation' => 'auth.login',
                'ip' => request()->ip(),
                'device' => request()->userAgent()
            ])
        );
    }

    public function validateSession(string $token): bool
    {
        if (!$this->sessions->isValid($token)) {
            throw new SecurityException('Invalid session');
        }

        if ($this->sessions->isExpired($token)) {
            throw new SecurityException('Session expired');
        }

        if ($this->sessions->requiresRevalidation($token)) {
            throw new SecurityException('Session requires revalidation');
        }

        $this->audit->logAccess('session.validate', [
            'token' => hash('sha256', $token),
            'ip' => request()->ip()
        ]);

        return true;
    }

    public function validateMFA(string $token, string $code): bool
    {
        return $this->security->executeCriticalOperation(
            new ValidateMFAOperation($token, $code, $this->mfa),
            new SecurityContext([
                'operation' => 'auth.mfa',
                'ip' => request()->ip()
            ])
        );
    }

    public function validatePermissions(User $user, string $permission): bool
    {
        return $this->security->executeCriticalOperation(
            new ValidatePermissionOperation($user, $permission),
            new SecurityContext([
                'operation' => 'auth.permission',
                'user' => $user->id,
                'permission' => $permission
            ])
        );
    }

    public function invalidateSession(string $token): void
    {
        $this->security->executeCriticalOperation(
            new InvalidateSessionOperation($token, $this->sessions),
            new SecurityContext([
                'operation' => 'auth.logout',
                'token' => hash('sha256', $token)
            ])
        );
    }
}

class SessionManager 
{
    private CacheManager $cache;
    private SecurityConfig $config;
    
    public function create(User $user): string
    {
        $token = $this->generateSecureToken();
        
        $this->cache->put(
            $this->getSessionKey($token),
            [
                'user_id' => $user->id,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'last_activity' => now(),
                'requires_revalidation' => false
            ],
            $this->config->get('session.lifetime')
        );

        return $token;
    }

    public function isValid(string $token): bool
    {
        $session = $this->cache->get($this->getSessionKey($token));
        
        if (!$session) {
            return false;
        }

        if ($session['ip'] !== request()->ip()) {
            return false;
        }

        if ($session['user_agent'] !== request()->userAgent()) {
            return false;
        }

        return true;
    }

    public function isExpired(string $token): bool
    {
        $session = $this->cache->get($this->getSessionKey($token));
        
        if (!$session) {
            return true;
        }

        $lastActivity = Carbon::parse($session['last_activity']);
        $lifetime = $this->config->get('session.lifetime');

        return $lastActivity->addMinutes($lifetime)->isPast();
    }

    public function requiresRevalidation(string $token): bool
    {
        $session = $this->cache->get($this->getSessionKey($token));
        return $session['requires_revalidation'] ?? false;
    }

    public function invalidate(string $token): void
    {
        $this->cache->forget($this->getSessionKey($token));
    }

    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function getSessionKey(string $token): string
    {
        return 'session.' . hash('sha256', $token);
    }
}

class MFAProvider
{
    private SecurityConfig $config;
    private CacheManager $cache;
    
    public function generateCode(User $user): string
    {
        $code = $this->generateSecureCode();
        
        $this->cache->put(
            $this->getMFAKey($user->id),
            [
                'code' => Hash::make($code),
                'attempts' => 0
            ],
            $this->config->get('mfa.code_lifetime')
        );

        return $code;
    }

    public function validateCode(User $user, string $code): bool
    {
        $mfa = $this->cache->get($this->getMFAKey($user->id));
        
        if (!$mfa) {
            return false;
        }

        if ($mfa['attempts'] >= $this->config->get('mfa.max_attempts')) {
            throw new SecurityException('Too many MFA attempts');
        }

        $this->cache->increment($this->getMFAKey($user->id) . '.attempts');

        return Hash::check($code, $mfa['code']);
    }

    private function generateSecureCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function getMFAKey(int $userId): string
    {
        return 'mfa.' . $userId;
    }
}

class AuthenticationOperation extends CriticalOperation
{
    private array $credentials;
    private MFAProvider $mfa;
    
    public function execute(): AuthResult
    {
        $user = User::where('email', $this->credentials['email'])->first();

        if (!$user || !Hash::check($this->credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if ($user->requires_mfa) {
            $code = $this->mfa->generateCode($user);
            // Send code via secure channel
            return new AuthResult(AuthResult::MFA_REQUIRED, null, ['mfa_token' => $code]);
        }

        $token = app(SessionManager::class)->create($user);
        return new AuthResult(AuthResult::SUCCESS, $user, ['token' => $token]);
    }

    public function getRules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string'
        ];
    }
}

class ValidateMFAOperation extends CriticalOperation
{
    private string $token;
    private string $code;
    private MFAProvider $mfa;
    
    public function execute(): bool
    {
        $session = Cache::get('mfa_pending.' . hash('sha256', $this->token));
        
        if (!$session) {
            throw new SecurityException('Invalid MFA session');
        }

        $user = User::findOrFail($session['user_id']);
        
        if (!$this->mfa->validateCode($user, $this->code)) {
            throw new SecurityException('Invalid MFA code');
        }

        return true;
    }
}

class ValidatePermissionOperation extends CriticalOperation
{
    private User $user;
    private string $permission;
    
    public function execute(): bool
    {
        return DB::transaction(function() {
            $roles = $this->user->roles()
                ->with(['permissions' => function($query) {
                    $query->where('name', $this->permission);
                }])
                ->get();

            foreach ($roles as $role) {
                if ($role->permissions->isNotEmpty()) {
                    return true;
                }
            }

            return false;
        });
    }
}
