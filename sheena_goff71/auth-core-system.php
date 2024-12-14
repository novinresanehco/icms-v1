<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Core\Security\SecurityService;

class AuthenticationSystem
{
    private SecurityService $security;

    public function __construct(SecurityService $security)
    {
        $this->security = $security;
    }

    public function authenticate(array $credentials): array
    {
        DB::beginTransaction();
        try {
            $user = DB::table('users')
                ->where('email', $credentials['email'])
                ->first();

            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                throw new AuthenticationException('Invalid credentials');
            }

            $token = $this->security->generateSecureToken();
            
            DB::table('auth_sessions')->insert([
                'user_id' => $user->id,
                'token' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(30),
                'created_at' => now()
            ]);

            DB::commit();

            return [
                'token' => $token,
                'expires_in' => 1800,
                'user_id' => $user->id
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function validateSession(string $token): bool
    {
        $hashedToken = hash('sha256', $token);
        
        $session = DB::table('auth_sessions')
            ->where('token', $hashedToken)
            ->where('expires_at', '>', now())
            ->first();

        return (bool) $session;
    }

    public function logout(string $token): void
    {
        DB::table('auth_sessions')
            ->where('token', hash('sha256', $token))
            ->delete();
    }

    public function refreshSession(string $token): array
    {
        DB::beginTransaction();
        try {
            $hashedToken = hash('sha256', $token);
            $session = DB::table('auth_sessions')
                ->where('token', $hashedToken)
                ->first();

            if (!$session) {
                throw new AuthenticationException('Invalid session');
            }

            $newToken = $this->security->generateSecureToken();

            DB::table('auth_sessions')
                ->where('token', $hashedToken)
                ->update([
                    'token' => hash('sha256', $newToken),
                    'expires_at' => now()->addMinutes(30),
                    'updated_at' => now()
                ]);

            DB::commit();

            return [
                'token' => $newToken,
                'expires_in' => 1800
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
