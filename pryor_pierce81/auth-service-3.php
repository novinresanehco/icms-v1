<?php

namespace App\Services;

use App\Core\Auth\AuthenticationService;
use App\Core\Contracts\AuthServiceInterface;
use App\Models\User;
use App\Core\Exceptions\AuthException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\NewAccessToken;
use Carbon\Carbon;

class AuthService implements AuthServiceInterface
{
    /**
     * @var int
     */
    protected const TOKEN_EXPIRATION = 60 * 24; // 24 hours
    
    /**
     * @var int
     */
    protected const MAX_ATTEMPTS = 5;

    /**
     * @var int
     */
    protected const LOCKOUT_TIME = 15; // minutes

    /**
     * Authenticate user and generate token
     *
     * @param array $credentials
     * @return array
     * @throws AuthException
     */
    public function login(array $credentials): array
    {
        $this->checkLoginAttempts($credentials['email']);

        try {
            $user = User::where('email', $credentials['email'])->first();

            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                $this->incrementLoginAttempts($credentials['email']);
                throw new AuthException('Invalid credentials');
            }

            if (!$user->is_active) {
                throw new AuthException('Account is deactivated');
            }

            $token = $this->generateToken($user);
            $this->clearLoginAttempts($credentials['email']);

            return [
                'user' => $user,
                'token' => $token->plainTextToken,
                'expires_at' => Carbon::now()->addMinutes(self::TOKEN_EXPIRATION)
            ];
        } catch (\Exception $e) {
            throw new AuthException($e->getMessage());
        }
    }

    /**
     * Register new user
     *
     * @param array $data
     * @return User
     * @throws AuthException
     */
    public function register(array $data): User
    {
        try {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'is_active' => true
            ]);

            // Send verification email
            $this->sendVerificationEmail($user);

            return $user;
        } catch (\Exception $e) {
            throw new AuthException('Registration failed: ' . $e->getMessage());
        }
    }

    /**
     * Logout user
     *
     * @param User $user
     * @return void
     * @throws AuthException
     */
    public function logout(User $user): void
    {
        try {
            // Revoke current token
            $user->currentAccessToken()->delete();
            
            // Clear user cache
            Cache::tags(['user:' . $user->id])->flush();
        } catch (\Exception $e) {
            throw new AuthException('Logout failed: ' . $e->getMessage());
        }
    }

    /**
     * Refresh user token
     *
     * @param User $user
     * @return array
     * @throws AuthException
     */
    public function refreshToken(User $user): array
    {
        try {
            // Revoke current token
            $user->currentAccessToken()->delete();

            // Generate new token
            $token = $this->generateToken($user);

            return [
                'token' => $token->plainTextToken,
                'expires_at' => Carbon::now()->addMinutes(self::TOKEN_EXPIRATION)
            ];
        } catch (\Exception $e) {
            throw new AuthException('Token refresh failed: ' . $e->getMessage());
        }
    }

    /**
     * Send password reset link
     *
     * @param string $email
     * @return void
     * @throws AuthException
     */
    public function sendPasswordResetLink(string $email): void
    {
        try {
            $user = User::where('email', $email)->firstOrFail();
            
            // Generate password reset token
            $token = $this->generatePasswordResetToken($user);
            
            // Send reset email
            $this->sendPasswordResetEmail($user, $token);
        } catch (\Exception $e) {
            throw new AuthException('Failed to send reset link: ' . $e->getMessage());
        }
    }

    /**
     * Reset user password
     *
     * @param array $data
     * @return void
     * @throws AuthException
     */
    public function resetPassword(array $data): void
    {
        try {
            $user = User::where('email', $data['email'])->firstOrFail();
            
            if (!$this->validatePasswordResetToken($user, $data['token'])) {
                throw new AuthException('Invalid or expired reset token');
            }

            $user->password = Hash::make($data['password']);
            $user->save();

            // Revoke all tokens
            $user->tokens()->delete();
            
            // Clear password reset token
            $this->clearPasswordResetToken($user);
        } catch (\Exception $e) {
            throw new AuthException('Password reset failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate authentication token
     *
     * @param User $user
     * @return NewAccessToken
     */
    protected function generateToken(User $user): NewAccessToken
    {
        return $user->createToken(
            'auth_token',
            ['*'],
            Carbon::now()->addMinutes(self::TOKEN_EXPIRATION)
        );
    }

    /**
     * Check login attempts
     *
     * @param string $email
     * @throws AuthException
     */
    protected function checkLoginAttempts(string $email): void
    {
        $attempts = Cache::get('login_attempts:' . $email, 0);
        
        if ($attempts >= self::MAX_ATTEMPTS) {
            $lockoutTime = Cache::get('login_lockout:' . $email);
            
            if ($lockoutTime > Carbon::now()) {
                throw new AuthException('Too many login attempts. Please try again later.');
            }
            
            $this->clearLoginAttempts($email);
        }
    }

    /**
     * Increment login attempts
     *
     * @param string $email
     * @return void
     */
    protected function incrementLoginAttempts(string $email): void
    {
        $attempts = Cache::get('login_attempts:' . $email, 0) + 1;
        Cache::put('login_attempts:' . $email, $attempts, Carbon::now()->addMinutes(self::LOCKOUT_TIME));
        
        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::put('login_lockout:' . $email, Carbon::now()->addMinutes(self::LOCKOUT_TIME));
        }
    }

    /**
     * Clear login attempts
     *
     * @param string $email
     * @return void
     */
    protected function clearLoginAttempts(string $email): void
    {
        Cache::forget('login_attempts:' . $email);
        Cache::forget('login_lockout:' . $email);
    }
}
