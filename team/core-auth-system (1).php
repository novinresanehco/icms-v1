<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Core\Security\EncryptionService;
use App\Core\Exceptions\{AuthException, SecurityException};

class AuthenticationManager
{
    private EncryptionService $encryption;
    private TokenManager $tokenManager;
    
    public function __construct(
        EncryptionService $encryption,
        TokenManager $tokenManager
    ) {
        $this->encryption = $encryption;
        $this->tokenManager = $tokenManager;
    }

    public function authenticate(array $credentials): AuthResult
    {
        DB::beginTransaction();
        
        try {
            $user = $this->validateCredentials($credentials);
            
            if (!$user) {
                throw new AuthException('Invalid credentials');
            }

            $token = $this->tokenManager->generateToken($user);
            $this->logSuccessfulAuth($user);
            
            DB::commit();
            
            return new AuthResult($user, $token);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logFailedAuth($credentials, $e);
            throw $e;
        }
    }

    public function validateSession(string $token): bool
    {
        return $this->tokenManager->validateToken($token);
    }

    protected function validateCredentials(array $credentials): ?User
    {
        $user = User::where('email', $credentials['email'])->first();
        
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        return $user;
    }

    protected function logSuccessfulAuth(User $user): void
    {
        DB::table('auth_logs')->insert([
            'user_id' => $user->id,
            'status' => 'success',
            'ip_address' => request()->ip(),
            'created_at' => now()
        ]);
    }

    protected function logFailedAuth(array $credentials, \Exception $e): void
    {
        DB::table('auth_logs')->insert([
            'email' => $credentials['email'],
            'status' => 'failed',
            'error' => $e->getMessage(),
            'ip_address' => request()->ip(),
            'created_at' => now()
        ]);
    }
}

class TokenManager
{
    private const TOKEN_LENGTH = 64;
    private const TOKEN_EXPIRY = 3600; // 1 hour
    
    public function generateToken(User $user): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        
        Cache::put(
            $this->getTokenKey($token),
            $user->id,
            self::TOKEN_EXPIRY
        );
        
        return $token;
    }
    
    public function validateToken(string $token): bool
    {
        return Cache::has($this->getTokenKey($token));
    }
    
    public function getUserFromToken(string $token): ?User
    {
        $userId = Cache::get($this->getTokenKey($token));
        return $userId ? User::find($userId) : null;
    }
    
    public function revokeToken(string $token): void
    {
        Cache::forget($this->getTokenKey($token));
    }
    
    protected function getTokenKey(string $token): string
    {
        return "auth_token:{$token}";
    }
}

class AuthMiddleware
{
    private AuthenticationManager $auth;

    public function __construct(AuthenticationManager $auth)
    {
        $this->auth = $auth;
    }

    public function handle($request, \Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token || !$this->auth->validateSession($token)) {
            throw new AuthException('Unauthorized');
        }

        return $next($request);
    }
}

class AccessControl
{
    public function hasPermission(User $user, string $permission): bool
    {
        return DB::table('user_permissions')
            ->where('user_id', $user->id)
            ->where('permission', $permission)
            ->exists();
    }

    public function grantPermission(User $user, string $permission): void
    {
        if (!$this->hasPermission($user, $permission)) {
            DB::table('user_permissions')->insert([
                'user_id' => $user->id,
                'permission' => $permission,
                'created_at' => now()
            ]);
        }
    }

    public function revokePermission(User $user, string $permission): void
    {
        DB::table('user_permissions')
            ->where('user_id', $user->id)
            ->where('permission', $permission)
            ->delete();
    }
}

class AuthController
{
    private AuthenticationManager $auth;

    public function __construct(AuthenticationManager $auth)
    {
        $this->auth = $auth;
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        try {
            $result = $this->auth->authenticate($credentials);
            return response()->json([
                'token' => $result->token,
                'user' => $result->user
            ]);
        } catch (AuthException $e) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }
    }

    public function logout(Request $request)
    {
        $this->auth->revokeToken($request->bearerToken());
        return response()->json(['message' => 'Logged out']);
    }
}
