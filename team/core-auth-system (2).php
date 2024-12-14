<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache, DB};
use App\Core\Security\Services\EncryptionService;
use App\Exceptions\AuthenticationException;

class AuthenticationManager
{
    private EncryptionService $encryption;
    private array $config;

    public function __construct(EncryptionService $encryption, array $config)
    {
        $this->encryption = $encryption;
        $this->config = $config;
    }

    public function authenticate(array $credentials): AuthResult
    {
        DB::beginTransaction();
        try {
            $user = $this->validateCredentials($credentials);
            $token = $this->generateToken($user);
            $this->saveSession($user, $token);
            DB::commit();
            return new AuthResult($user, $token);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuthenticationException('Authentication failed', 0, $e);
        }
    }

    protected function validateCredentials(array $credentials): User
    {
        $user = DB::table('users')->where('email', $credentials['email'])->first();
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }
        return $user;
    }

    public function verifyToken(string $token): bool
    {
        try {
            $payload = $this->encryption->decrypt($token);
            $data = json_decode($payload, true);
            
            if (!$this->validateTokenData($data)) {
                return false;
            }

            if (!Cache::has("auth_token:{$data['id']}")) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function generateToken(User $user): string
    {
        $payload = json_encode([
            'id' => $user->id,
            'type' => 'access',
            'exp' => time() + $this->config['token_lifetime']
        ]);
        
        return $this->encryption->encrypt($payload);
    }

    protected function saveSession(User $user, string $token): void
    {
        Cache::put(
            "auth_token:{$user->id}",
            $token,
            $this->config['token_lifetime']
        );
        
        Cache::put(
            "user_session:{$user->id}",
            [
                'last_activity' => time(),
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ],
            $this->config['session_lifetime']
        );
    }

    public function logout(string $token): void
    {
        try {
            $payload = $this->encryption->decrypt($token);
            $data = json_decode($payload, true);
            
            Cache::forget("auth_token:{$data['id']}");
            Cache::forget("user_session:{$data['id']}");
        } catch (\Exception $e) {
            // Silent fail on logout
        }
    }

    protected function validateTokenData(array $data): bool
    {
        return isset($data['id']) && 
               isset($data['type']) && 
               isset($data['exp']) && 
               $data['exp'] > time() &&
               $data['type'] === 'access';
    }

    public function refreshSession(string $token): string
    {
        DB::beginTransaction();
        try {
            $payload = $this->encryption->decrypt($token);
            $data = json_decode($payload, true);
            
            $user = DB::table('users')->find($data['id']);
            if (!$user) {
                throw new AuthenticationException('User not found');
            }
            
            $newToken = $this->generateToken($user);
            $this->saveSession($user, $newToken);
            
            DB::commit();
            return $newToken;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuthenticationException('Session refresh failed', 0, $e);
        }
    }
}

class AuthResult
{
    public function __construct(
        public readonly User $user,
        public readonly string $token
    ) {}
}

final class User
{
    public readonly int $id;
    public readonly string $email;
    public readonly array $roles;

    public function __construct(array $data)
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->roles = $data['roles'] ?? [];
    }
}
