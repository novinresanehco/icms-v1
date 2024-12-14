<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{Hash, Session};
use App\Core\Services\{ValidationService, EncryptionService};

class AuthenticationManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private EncryptionService $encryption;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator, 
        EncryptionService $encryption
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->encryption = $encryption;
    }

    public function authenticate(array $credentials): AuthResult
    {
        $validated = $this->validator->validate($credentials, [
            'email' => 'required|email',
            'password' => 'required|string|min:8'
        ]);

        $user = DB::table('users')->where('email', $validated['email'])->first();
        
        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }

        if (!$user->is_active) {
            throw new AuthenticationException('Account is disabled');
        }

        $token = $this->generateSecureToken();
        $this->storeSession($user->id, $token);

        return new AuthResult($user, $token);
    }

    public function validateSession(string $token): bool
    {
        $session = DB::table('user_sessions')
            ->where('token', $this->encryption->hash($token))
            ->where('expires_at', '>', now())
            ->first();

        return $session !== null;
    }

    public function logout(string $token): void
    {
        DB::table('user_sessions')
            ->where('token', $this->encryption->hash($token))
            ->delete();
    }

    public function refreshSession(string $token): string
    {
        $session = DB::table('user_sessions')
            ->where('token', $this->encryption->hash($token))
            ->first();

        if (!$session) {
            throw new AuthenticationException('Invalid session');
        }

        $newToken = $this->generateSecureToken();
        
        DB::table('user_sessions')
            ->where('id', $session->id)
            ->update([
                'token' => $this->encryption->hash($newToken),
                'expires_at' => now()->addHours(24)
            ]);

        return $newToken;
    }

    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function storeSession(int $userId, string $token): void
    {
        DB::table('user_sessions')->insert([
            'user_id' => $userId,
            'token' => $this->encryption->hash($token),
            'expires_at' => now()->addHours(24),
            'created_at' => now()
        ]);
    }
}

class AuthResult
{
    public $user;
    public string $token;
    public array $permissions;

    public function __construct($user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
        $this->permissions = $this->loadPermissions($user->id);
    }

    private function loadPermissions(int $userId): array
    {
        return DB::table('user_permissions')
            ->where('user_id', $userId)
            ->pluck('permission')
            ->toArray();
    }
}

class AuthMiddleware
{
    private AuthenticationManager $auth;

    public function __construct(AuthenticationManager $auth)
    {
        $this->auth = $auth;
    }

    public function handle($request, $next)
    {
        $token = $request->bearerToken();

        if (!$token || !$this->auth->validateSession($token)) {
            throw new AuthenticationException('Invalid or expired session');
        }

        return $next($request);
    }
}
