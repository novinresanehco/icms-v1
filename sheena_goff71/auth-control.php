<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Hash, Cache, Log, DB};
use App\Core\Services\{ValidationService, SecurityService};
use App\Core\Models\{User, Role, Permission, SecurityLog};
use App\Core\Exceptions\{AuthException, SecurityException};
use Illuminate\Contracts\Auth\Authenticatable;

class AuthenticationControl 
{
    private ValidationService $validator;
    private SecurityService $security;
    private array $securityConfig;
    
    private const MAX_ATTEMPTS = 3;
    private const LOCKOUT_TIME = 900; // 15 minutes
    private const SESSION_LIFETIME = 3600; // 1 hour
    
    public function __construct(
        ValidationService $validator,
        SecurityService $security,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->security = $security;
        $this->securityConfig = $securityConfig;
    }

    public function authenticate(array $credentials): Authenticatable
    {
        $this->validateLoginAttempt($credentials['email']);

        DB::beginTransaction();
        try {
            $user = $this->verifyCredentials($credentials);
            $this->validateMfa($user, $credentials['mfa_code'] ?? null);
            $this->setupUserSession($user);
            
            DB::commit();
            
            $this->clearLoginAttempts($credentials['email']);
            $this->logSuccessfulLogin($user);
            
            return $user;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailedLogin($credentials['email'], $e);
            throw new AuthException('Authentication failed: ' . $e->getMessage());
        }
    }

    public function validateAccess(Authenticatable $user, string $permission): bool
    {
        try {
            $this->validateSession($user);
            $this->validateUserStatus($user);
            
            $hasPermission = $this->checkPermission($user, $permission);
            
            $this->logAccessAttempt($user, $permission, $hasPermission);
            
            return $hasPermission;
            
        } catch (\Exception $e) {
            $this->logAccessFailure($user, $permission, $e);
            throw new SecurityException('Access validation failed: ' . $e->getMessage());
        }
    }

    public function refreshSession(Authenticatable $user): void
    {
        if (!$this->validateSessionRefresh($user)) {
            throw new SecurityException('Session refresh denied');
        }

        $this->extendSession($user);
        $this->logSessionRefresh($user);
    }

    protected function verifyCredentials(array $credentials): User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthException('Invalid credentials');
        }

        return $user;
    }

    protected function validateMfa(User $user, ?string $code): void
    {
        if ($this->securityConfig['mfa_required'] && !$this->verifyMfaCode($user, $code)) {
            throw new SecurityException('Invalid MFA code');
        }
    }

    protected function validateSession(Authenticatable $user): void
    {
        $session = Cache::get("user_session.{$user->id}");
        
        if (!$session || 
            $session['expires_at'] < now() || 
            $session['ip'] !== request()->ip()) {
            throw new SecurityException('Invalid session');
        }
    }

    protected function setupUserSession(User $user): void
    {
        $sessionData = [
            'id' => $user->id,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'expires_at' => now()->addSeconds(self::SESSION_LIFETIME),
            'mfa_verified' => true,
            'permissions' => $this->getUserPermissions($user)
        ];

        Cache::put(
            "user_session.{$user->id}", 
            $sessionData, 
            self::SESSION_LIFETIME
        );
    }

    protected function validateLoginAttempt(string $email): void
    {
        $attempts = Cache::get("login_attempts.{$email}", 0);
        
        if ($attempts >= self::MAX_ATTEMPTS) {
            throw new SecurityException('Account locked due to multiple failed attempts');
        }
    }

    protected function handleFailedLogin(string $email, \Exception $e): void
    {
        $attempts = Cache::increment("login_attempts.{$email}", 1);
        
        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::put("login_attempts.{$email}", $attempts, self::LOCKOUT_TIME);
            $this->notifyAccountLocked($email);
        }

        $this->logFailedLogin($email, $e);
    }

    protected function checkPermission(Authenticatable $user, string $permission): bool
    {
        return Cache::remember(
            "user_permission.{$user->id}.{$permission}",
            300,
            function() use ($user, $permission) {
                return $user->roles()
                    ->whereHas('permissions', function($query) use ($permission) {
                        $query->where('name', $permission);
                    })->exists();
            }
        );
    }

    protected function getUserPermissions(User $user): array
    {
        return Cache::remember(
            "user_permissions.{$user->id}",
            300,
            function() use ($user) {
                return $user->roles()
                    ->with('permissions')
                    ->get()
                    ->pluck('permissions')
                    ->flatten()
                    ->pluck('name')
                    ->unique()
                    ->toArray();
            }
        );
    }

    protected function extendSession(Authenticatable $user): void
    {
        $session = Cache::get("user_session.{$user->id}");
        $session['expires_at'] = now()->addSeconds(self::SESSION_LIFETIME);
        
        Cache::put(
            "user_session.{$user->id}",
            $session,
            self::SESSION_LIFETIME
        );
    }

    protected function logSuccessfulLogin(User $user): void
    {
        SecurityLog::create([
            'type' => 'login_success',
            'user_id' => $user->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'details' => ['session_id' => session()->getId()]
        ]);
    }

    protected function logFailedLogin(string $email, \Exception $e): void
    {
        SecurityLog::create([
            'type' => 'login_failed',
            'details' => [
                'email' => $email,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'error' => $e->getMessage()
            ]
        ]);
    }

    protected function logAccessAttempt(
        Authenticatable $user,
        string $permission,
        bool $granted
    ): void {
        SecurityLog::create([
            'type' => $granted ? 'access_granted' : 'access_denied',
            'user_id' => $user->id,
            'details' => [
                'permission' => $permission,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]
        ]);
    }

    protected function validateSessionRefresh(Authenticatable $user): bool
    {
        $session = Cache::get("user_session.{$user->id}");
        
        return $session && 
               $session['ip'] === request()->ip() &&
               $session['user_agent'] === request()->userAgent();
    }
}
