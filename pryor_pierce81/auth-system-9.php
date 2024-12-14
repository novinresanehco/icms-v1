<?php

namespace App\Core\Auth;

use App\Core\Auth\Contracts\AuthenticationInterface;
use App\Core\Auth\Exceptions\AuthenticationException;
use App\Core\Auth\Services\TokenService;
use App\Core\Auth\Events\UserAuthenticated;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;

class AuthenticationManager implements AuthenticationInterface
{
    protected TokenService $tokenService;
    protected array $config;
    
    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
        $this->config = config('auth');
    }

    /**
     * Authenticate a user with credentials
     *
     * @param array $credentials
     * @return AuthResult
     * @throws AuthenticationException
     */
    public function authenticate(array $credentials): AuthResult
    {
        try {
            $this->validateCredentials($credentials);
            
            $user = $this->findUser($credentials);
            
            if (!$user || !$this->validatePassword($credentials['password'], $user->password)) {
                throw new AuthenticationException('Invalid credentials');
            }
            
            if (!$this->isUserActive($user)) {
                throw new AuthenticationException('User account is not active');
            }
            
            $token = $this->tokenService->generateToken($user);
            
            event(new UserAuthenticated($user));
            
            return new AuthResult([
                'success' => true,
                'user' => $user,
                'token' => $token
            ]);
            
        } catch (\Exception $e) {
            throw new AuthenticationException($e->getMessage());
        }
    }
    
    /**
     * Validate user credentials
     */
    protected function validateCredentials(array $credentials): void
    {
        if (!isset($credentials['email']) || !isset($credentials['password'])) {
            throw new AuthenticationException('Missing required credentials');
        }
        
        if (!filter_var($credentials['email'], FILTER_VALIDATE_EMAIL)) {
            throw new AuthenticationException('Invalid email format');
        }
    }
    
    /**
     * Find user by credentials
     */
    protected function findUser(array $credentials): ?User
    {
        return User::where('email', $credentials['email'])->first();
    }
    
    /**
     * Validate user password
     */
    protected function validatePassword(string $input, string $hash): bool
    {
        return Hash::check($input, $hash);
    }
    
    /**
     * Check if user account is active
     */
    protected function isUserActive(User $user): bool
    {
        return $user->status === 'active' && !$user->banned_at;
    }
}

// Token Service Implementation
namespace App\Core\Auth\Services;

class TokenService
{
    protected string $algorithm = 'HS256';
    protected int $lifetime;
    
    public function __construct()
    {
        $this->lifetime = config('auth.token_lifetime', 3600);
    }
    
    public function generateToken(User $user): string
    {
        $payload = [
            'sub' => $user->id,
            'email' => $user->email,
            'iat' => time(),
            'exp' => time() + $this->lifetime
        ];
        
        return JWT::encode($payload, config('app.key'), $this->algorithm);
    }
    
    public function validateToken(string $token): bool
    {
        try {
            $decoded = JWT::decode($token, config('app.key'), [$this->algorithm]);
            return !$this->isTokenBlacklisted($token) && $decoded->exp > time();
        } catch (\Exception $e) {
            return false;
        }
    }
    
    protected function isTokenBlacklisted(string $token): bool
    {
        return Cache::has('blacklisted_token:' . $token);
    }
}

// Authorization Service
namespace App\Core\Auth\Services;

class AuthorizationService
{
    public function authorize(User $user, string $permission): bool
    {
        // Check for superadmin
        if ($user->isSuperAdmin()) {
            return true;
        }
        
        // Check direct permissions
        if ($user->hasDirectPermission($permission)) {
            return true;
        }
        
        // Check role permissions
        foreach ($user->roles as $role) {
            if ($role->hasPermission($permission)) {
                return true;
            }
        }
        
        return false;
    }
    
    public function authorizeForContent(User $user, Content $content, string $action): bool
    {
        // Owner always has access
        if ($content->user_id === $user->id) {
            return true;
        }
        
        // Check content-specific permissions
        $permission = "content.{$action}";
        return $this->authorize($user, $permission);
    }
}

// Security Service
namespace App\Core\Auth\Services;

class SecurityService
{
    protected array $securityConfig;
    
    public function __construct()
    {
        $this->securityConfig = config('security');
    }
    
    public function validatePasswordStrength(string $password): bool
    {
        $minLength = $this->securityConfig['password_min_length'] ?? 8;
        
        return strlen($password) >= $minLength &&
               preg_match('/[A-Z]/', $password) && // Has uppercase
               preg_match('/[a-z]/', $password) && // Has lowercase
               preg_match('/[0-9]/', $password) && // Has number
               preg_match('/[^A-Za-z0-9]/', $password); // Has special char
    }
    
    public function detectSuspiciousActivity(Request $request): bool
    {
        $attempts = Cache::get('login_attempts:' . $request->ip(), 0);
        
        if ($attempts > $this->securityConfig['max_login_attempts']) {
            $this->blockIP($request->ip());
            return true;
        }
        
        return false;
    }
    
    protected function blockIP(string $ip): void
    {
        Cache::put(
            'blocked_ip:' . $ip,
            true,
            now()->addMinutes($this->securityConfig['ip_block_duration'])
        );
    }
}
