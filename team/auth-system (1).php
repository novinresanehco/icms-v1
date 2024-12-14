<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Contracts\{AuthManagerInterface, RoleRepositoryInterface};
use App\Core\Exceptions\{AuthException, SecurityException};
use Illuminate\Support\Facades\{Hash, Event};

class AuthManager implements AuthManagerInterface
{
    private SecurityManager $security;
    private RoleRepositoryInterface $roles;
    private CacheManager $cache;
    private int $maxAttempts = 5;
    private int $lockoutTime = 900; // 15 minutes

    public function __construct(
        SecurityManager $security,
        RoleRepositoryInterface $roles,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->roles = $roles;
        $this->cache = $cache;
    }

    public function authenticate(array $credentials): AuthResult 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeAuthentication($credentials),
            ['action' => 'authenticate', 'username' => $credentials['username']]
        );
    }

    private function executeAuthentication(array $credentials): AuthResult
    {
        // Check for too many failed attempts
        $this->checkLockout($credentials['username']);

        // Find user
        $user = User::where('username', $credentials['username'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            $this->recordFailedAttempt($credentials['username']);
            throw new AuthException('Invalid credentials');
        }

        // Verify MFA if enabled
        if ($user->mfa_enabled) {
            $this->verifyMFA($user, $credentials['mfa_code'] ?? null);
        }

        // Create session with strict security
        $token = $this->createSecureSession($user);

        // Clear failed attempts
        $this->clearFailedAttempts($credentials['username']);

        // Return success result
        return new AuthResult($user, $token);
    }

    private function checkLockout(string $username): void
    {
        $attempts = $this->getFailedAttempts($username);
        
        if ($attempts >= $this->maxAttempts) {
            $lockoutUntil = $this->getLockoutTime($username);
            if (time() < $lockoutUntil) {
                throw new SecurityException('Account is locked. Please try again later.');
            }
            // Reset if lockout has expired
            $this->clearFailedAttempts($username);
        }
    }

    public function verifyPermission(User $user, string $permission): bool
    {
        return $this->cache->remember(
            "user:{$user->id}:permission:{$permission}",
            3600,
            fn() => $this->executePermissionCheck($user, $permission)
        );
    }

    private function executePermissionCheck(User $user, string $permission): bool
    {
        // Get user's roles with permissions
        $roles = $this->roles->getUserRoles($user->id);

        // Check each role for the permission
        foreach ($roles as $role) {
            if ($role->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    private function createSecureSession(User $user): string
    {
        // Generate cryptographically secure token
        $token = bin2hex(random_bytes(32));

        // Store session with strict security settings
        Session::create([
            'user_id' => $user->id,
            'token' => Hash::make($token),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'expires_at' => now()->addMinutes(config('auth.session_lifetime')),
            'mfa_verified' => $user->mfa_enabled ? true : null
        ]);

        return $token;
    }

    private function verifyMFA(User $user, ?string $code): void
    {
        if (!$code) {
            throw new AuthException('MFA code required');
        }

        if (!$this->verifyTOTP($user->mfa_secret, $code)) {
            $this->recordFailedAttempt($user->username);
            throw new AuthException('Invalid MFA code');
        }
    }

    private function verifyTOTP(string $secret, string $code): bool
    {
        // Verify TOTP code with time drift window
        $window = 1; // One interval before and after
        
        for ($i = -$window; $i <= $window; $i++) {
            $expectedCode = $this->generateTOTP($secret, time() + ($i * 30));
            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }

    private function getFailedAttempts(string $username): int
    {
        return (int) $this->cache->get("auth:failed:$username", 0);
    }

    private function recordFailedAttempt(string $username): void
    {
        $attempts = $this->getFailedAttempts($username) + 1;
        $this->cache->put("auth:failed:$username", $attempts, 3600);

        if ($attempts >= $this->maxAttempts) {
            $this->cache->put("auth:lockout:$username", time() + $this->lockoutTime, $this->lockoutTime);
            Event::dispatch(new AccountLocked($username));
        }
    }

    private function getLockoutTime(string $username): int
    {
        return (int) $this->cache->get("auth:lockout:$username", 0);
    }

    private function clearFailedAttempts(string $username): void
    {
        $this->cache->forget("auth:failed:$username");
        $this->cache->forget("auth:lockout:$username");
    }
}
