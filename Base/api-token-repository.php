<?php

namespace App\Repositories;

use App\Models\ApiToken;
use App\Repositories\Contracts\ApiTokenRepositoryInterface;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

class ApiTokenRepository extends BaseRepository implements ApiTokenRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['status', 'type', 'user_id'];

    public function createToken(array $data): ApiToken
    {
        $token = Str::random(64);
        $hashedToken = hash('sha256', $token);

        $apiToken = $this->create([
            'name' => $data['name'],
            'token' => $hashedToken,
            'abilities' => $data['abilities'] ?? ['*'],
            'user_id' => $data['user_id'] ?? auth()->id(),
            'expires_at' => $data['expires_at'] ?? null,
            'last_used_at' => null,
            'status' => 'active'
        ]);

        $apiToken->plainTextToken = $token;
        return $apiToken;
    }

    public function findByToken(string $token): ?ApiToken
    {
        $hashedToken = hash('sha256', $token);
        
        return $this->model
            ->where('token', $hashedToken)
            ->where('status', 'active')
            ->where(function($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    public function revokeToken(int $id): bool
    {
        try {
            return $this->update($id, [
                'status' => 'revoked',
                'revoked_at' => now()
            ]);
        } catch (\Exception $e) {
            \Log::error('Error revoking API token: ' . $e->getMessage());
            return false;
        }
    }

    public function updateLastUsed(int $id): bool
    {
        try {
            return $this->update($id, [
                'last_used_at' => now()
            ]);
        } catch (\Exception $e) {
            \Log::error('Error updating API token last used: ' . $e->getMessage());
            return false;
        }
    }

    public function getActiveTokensForUser(int $userId): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where(function($query) {
                $query->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
            })
            ->get();
    }

    public function revokeExpiredTokens(): int
    {
        try {
            return $this->model
                ->where('status', 'active')
                ->where('expires_at', '<=', now())
                ->update([
                    'status' => 'expired',
                    'revoked_at' => now()
                ]);
        } catch (\Exception $e) {
            \Log::error('Error revoking expired tokens: ' . $e->getMessage());
            return 0;
        }
    }
}
