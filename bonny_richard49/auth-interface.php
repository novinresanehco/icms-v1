<?php

namespace App\Core\Interfaces;

interface AuthServiceInterface
{
    public function authenticate(array $credentials): array;
    public function validateToken(string $token): bool;
    public function refreshToken(string $refreshToken): array;
    public function invalidateTokens(int $userId): void;
    public function resetPassword(string $email): bool;
    public function validateResetToken(string $token): bool;
}
