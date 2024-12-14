<?php

namespace App\Core\Auth\Repositories;

use App\Core\User\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Collection;

class AuthRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)
                  ->with(['roles.permissions', 'permissions'])
                  ->first();
    }

    public function findToken(string $token): ?PersonalAccessToken
    {
        $tokenId = explode('|', $token)[0] ?? null;
        return PersonalAccessToken::find($tokenId);
    }

    public function getUserTokens(User $user): Collection
    {
        return $user->tokens()
                    ->select(['id', 'name', 'created_at', 'last_used_at'])
                    ->get();
    }

    public function deleteExpiredTokens(): int
    {
        return PersonalAccessToken::where('expires_at', '<', now())->delete();
    }

    public function revokeAllTokens(User $user): int
    {
        return $user->tokens()->delete();
    }

    public function revokeTokenByDevice(User $user, string $deviceId): bool
    {
        return $user->tokens()->where('name', $deviceId)->delete() > 0;
    }

    public function createLoginAttemptRecord(string $email, bool $success): void
    {
        LoginAttempt::create([
            'email' => $email,
            'success' => $success,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    public function getLoginAttempts(string $email, int $minutes = 60): Collection
    {
        return LoginAttempt::where('email', $email)
                          ->where('created_at', '>=', now()->subMinutes($minutes))
                          ->get();
    }
}
