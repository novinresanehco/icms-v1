<?php

namespace App\Core\Auth\Services;

use App\Core\Auth\Repositories\AuthRepository;
use App\Exceptions\AuthValidationException;
use Illuminate\Support\Facades\Validator;

class AuthValidator
{
    public function __construct(private AuthRepository $repository)
    {
    }

    public function validateLogin(array $credentials): void
    {
        $validator = Validator::make($credentials, [
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            throw new AuthValidationException(
                'Login validation failed',
                $validator->errors()->toArray()
            );
        }

        $this->validateLoginAttempts($credentials['email']);
    }

    protected function validateLoginAttempts(string $email): void
    {
        $attempts = $this->repository->getLoginAttempts($email);
        
        if ($attempts->where('success', false)->count() >= config('auth.max_attempts', 5)) {
            throw new AuthValidationException(
                'Too many failed login attempts. Please try again later.'
            );
        }
    }

    public function validateToken(string $token): void
    {
        if (empty($token)) {
            throw new AuthValidationException('Token is required');
        }

        if (!str_contains($token, '|')) {
            throw new AuthValidationException('Invalid token format');
        }
    }

    public function validateDeviceId(string $deviceId): void
    {
        $validator = Validator::make(
            ['device_id' => $deviceId],
            ['device_id' => 'required|string|max:255']
        );

        if ($validator->fails()) {
            throw new AuthValidationException(
                'Invalid device ID',
                $validator->errors()->toArray()
            );
        }
    }
}
