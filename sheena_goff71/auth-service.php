<?php

namespace App\Core\Auth\Services;

use App\Core\Auth\Repositories\AuthRepository;
use App\Core\User\Models\User;
use App\Core\Auth\Events\{UserLoggedIn, UserLoggedOut, LoginFailed};
use Illuminate\Support\Facades\{Hash, Auth, Event};
use Laravel\Sanctum\NewAccessToken;

class AuthService
{
    public function __construct(
        private AuthRepository $repository,
        private AuthValidator $validator
    ) {}

    public function login(array $credentials): array
    {
        $this->validator->validateLogin($credentials);

        $user = $this->repository->findByEmail($credentials['email']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            event(new LoginFailed($credentials['email']));
            throw new AuthenticationException('Invalid credentials');
        }

        if (!$user->isActive()) {
            throw new AuthenticationException('Account is not active');
        }

        $token = $this->createToken($user, $credentials['device_name'] ?? null);
        $this->recordSuccessfulLogin($user);

        return [
            'user' => $user,
            'token' => $token->plainTextToken,
            'permissions' => $this->getUserPermissions($user)
        ];
    }

    public function logout(User $user, ?string $deviceId = null): bool
    {
        if ($deviceId) {
            $user->tokens()->where('name', $deviceId)->delete();
        } else {
            $user->tokens()->delete();
        }

        event(new UserLoggedOut($user));
        return true;
    }

    public function refreshToken(User $user, string $deviceId): NewAccessToken
    {
        $user->tokens()->where('name', $deviceId)->delete();
        return $this->createToken($user, $deviceId);
    }

    public function validateToken(string $token): bool
    {
        try {
            $tokenModel = $this->repository->findToken($token);
            return $tokenModel && !$tokenModel->isExpired();
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function createToken(User $user, ?string $deviceName = null): NewAccessToken
    {
        return $user->createToken(
            $deviceName ?? 'default_device',
            ['*'],
            now()->addDays(config('auth.token_lifetime', 7))
        );
    }

    protected function recordSuccessfulLogin(User $user): void
    {
        $user->update(['last_login_at' => now()]);
        $user->recordActivity('login');
        event(new UserLoggedIn($user));
    }

    protected function getUserPermissions(User $user): array
    {
        return array_merge(
            $user->permissions->pluck('name')->toArray(),
            $user->roles->flatMap->permissions->pluck('name')->unique()->toArray()
        );
    }
}
