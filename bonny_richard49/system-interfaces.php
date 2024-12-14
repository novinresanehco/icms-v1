<?php

namespace App\Core\Contracts;

interface TokenManagerInterface
{
    public function createToken(User $user, array $claims = []): Token;
    public function validateToken(string $tokenString): bool;
    public function revokeToken(string $tokenString): void;
}

interface EncryptionInterface 
{
    public function encrypt(string $data): string;
    public function decrypt(string $data): string;
    public function hash(string $data): string;
    public function verify(string $data, string $hash): bool;
}

interface SecurityInterface
{
    public function authenticate(array $credentials): Token;
    public function validateRequest(Request $request): bool;
}

interface AuditInterface
{
    public function logTokenCreation(Token $token): void;
    public function logTokenValidationFailure(string $token, \Exception $e): void;
    public function logTokenRevocation(string $token): void;
    public function logSecurityEvent(string $event, array $context): void;
    public function logValidationFailure(array $data, array $errors): void;
    public function logSystemFailure(\Exception $e): void;
}

interface MetricsInterface
{
    public function recordEvent(string $event, array $context): void;
    public function getMetrics(): array;
}

interface NotificationInterface
{
    public function notifySecurityTeam(string $event, array $context): void;
    public function notifySystemFailure(\Exception $e): void;
    public function notifyThresholdExceeded(string $event, string $metric, $value, $threshold): void;
}

interface ValidationInterface
{
    public function validate($data, array $rules = []): bool;
    public function validateTokenPayload(array $payload): bool;
    public function getErrors(): array;
}

interface CacheInterface
{
    public function get(string $key);
    public function set(string $key, $value, int $ttl = null): bool;
    public function has(string $key): bool;
    public function forget(string $key): bool;
    public function tags(array $tags);
}

interface LoggerInterface
{
    public function emergency(string $message, array $context = []): void;
    public function alert(string $message, array $context = []): void;
    public function critical(string $message, array $context = []): void;
    public function error(string $message, array $context = []): void;
    public function warning(string $message, array $context = []): void;
    public function notice(string $message, array $context = []): void;
    public function info(string $message, array $context = []): void;
    public function debug(string $message, array $context = []): void;
    public function log(string $level, string $message, array $context = []): void;
}

interface NotificationChannelInterface
{
    public function send($recipients, string $message, array $context): void;
}

interface RepositoryInterface
{
    public function find($id);
    public function create(array $data);
    public function update($id, array $data);
    public function delete($id): bool;
}

interface AuthenticationInterface
{
    public function attempt(array $credentials): bool;
    public function login(User $user): void;
    public function logout(): void;
    public function check(): bool;
    public function guest(): bool;
    public function user(): ?User;
}
