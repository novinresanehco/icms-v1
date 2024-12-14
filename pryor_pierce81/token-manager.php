<?php

namespace App\Core\Security;

use App\Models\User;
use App\Core\Exceptions\TokenException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;

class TokenManager
{
    protected string $algorithm = 'HS256';
    protected int $ttl = 3600; // 1 hour
    protected int $refreshTtl = 604800; // 1 week

    public function createToken(User $user): string
    {
        try {
            $payload = [
                'sub' => $user->id,
                'jti' => Str::random(16),
                'iat' => time(),
                'exp' => time() + $this->ttl,
                'role' => $user->roles->pluck('name')->toArray(),
                'permissions' => $user->getAllPermissions()->pluck('name')->toArray()
            ];

            return JWT::encode($payload, config('app.key'), $this->algorithm);

        } catch (\Exception $e) {
            throw new TokenException("Failed to create token: {$e->getMessage()}", 0, $e);
        }
    }

    public function verifyToken(string $token): object
    {
        try {
            return JWT::decode($token, new Key(config('app.key'), $this->algorithm));
        } catch (\Exception $e) {
            throw new TokenException("Invalid token: {$e->getMessage()}", 0, $e);
        }
    }

    public function refreshToken(string $token): string
    {
        try {
            $payload = $this->verifyToken($token);
            
            if (time() - $payload->iat > $this->refreshTtl) {
                throw new TokenException("Token has expired and cannot be refreshed");
            }

            $user = User::find($payload->sub);
            return $this->createToken($user);

        } catch (\Exception $e) {
            throw new TokenException("Failed to refresh token: {