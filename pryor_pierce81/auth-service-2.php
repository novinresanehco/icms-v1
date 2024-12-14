<?php

namespace App\Core\Services;

use App\Core\Repository\AuthRepository;
use App\Core\Validation\AuthValidator;
use App\Core\Events\AuthEvents;
use App\Core\Security\TokenManager;
use App\Core\Exceptions\AuthServiceException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthService
{
    public function __construct(
        protected AuthRepository $repository,
        protected AuthValidator $validator,
        protected TokenManager $tokenManager
    ) {}

    public function authenticate(array $credentials): array
    {
        $this->validator->validateCredentials($credentials);

        try {
            $user = $this->repository->model
                ->where('email', $credentials['email'])
                ->first();

            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                throw new AuthServiceException('Invalid credentials');
            }

            if ($user->status !== 'active') {
                throw new AuthServiceException('Account is not active');
            }

            $token = $this->tokenManager->createToken($user);
            event(new AuthEvents\UserLoggedIn($user));

            return [
                'user' => $user,
                'token' => $token
            ];

        } catch (\Exception $e) {
            throw new AuthServiceException("Authentication failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function register(array $data): User
    {
        $this->validator->validateRegistration($data);

        try {
            $user = $this->repository->createUser($data);
            event(new AuthEvents\UserRegistered($user));
            return $user;

        } catch (\Exception $e) {
            throw new AuthServiceException("Registration failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function forgotPassword(string $email): void
    {
        try {
            $status = Password::sendResetLink(['email' => $email]);

            if ($status !== Password::RESET_LINK_SENT) {
                throw new AuthServiceException(__($status));
            }

        } catch (\Exception $e) {
            throw new AuthServiceException("Failed to send reset link: {$e->getMessage()}", 0, $e);
        }
    }

    public function resetPassword(array $data): void
    {
        $this->validator->validatePasswordReset($data);

        try {
            $status = Password::reset($data, function($user, $password) {
                $user->password = Hash::make($password);
                $user->setRememberToken(Str::random(60));
                $user->save();

                event(new AuthEvents\PasswordReset($user));
            });

            if ($status !== Password::PASSWORD_RESET) {
                throw new AuthServiceException(__($status));
            }

        } catch (\Exception $e) {
            throw new AuthServiceException("Failed to reset password: {$e->getMessage()}", 0, $e);
        }
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        try {
            if (!Hash::check($currentPassword, $user->password)) {
                throw new AuthServiceException('Current password is incorrect');
            }

            $user->password = Hash::make($newPassword);
            $user->save();

            event(new AuthEvents\PasswordChanged($user));

        } catch (\Exception $e) {
            throw new AuthServiceException("Failed to change password: {$e->getMessage()}", 0, $e);
        }
    }

    public function logout(User $user): void
    {
        try {
            $this->tokenManager->revokeTokens($user);
            event(new AuthEvents\UserLoggedOut($user));

        } catch (\Exception $e) {
            throw new AuthServiceException("Logout failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function verifyEmail(string $token): void
    {
        try {
            $user = $this->repository->model
                ->where('verification_token', $token)
                ->first();

            if (!$user) {
                throw new AuthServiceException('Invalid verification token');
            }

            $user->email_verified_at = now();
            $user->verification_token = null;
            $user->save();

            event(new AuthEvents\EmailVerified($user));

        } catch (\Exception $e) {
            throw new AuthServiceException("Email verification failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function refreshToken(string $token): string
    {
        try {
            return $this->tokenManager->refreshToken($token);
        } catch (\Exception $e) {
            throw new AuthServiceException("Token refresh failed: {$e->getMessage()}", 0, $e);
        }
    }
}
