<?php

namespace App\Core\Interfaces;

interface SecurityManagerInterface
{
    public function validateRequest(Request $request): SecurityContext;
    public function encryptSensitiveData(array $data): array;
    public function decryptSensitiveData(array $data): array;
    public function sanitizeData(array $data): array;
}

interface CacheInterface
{
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed;
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): void;
    public function has(string $key): bool;
    public function forget(string $key): void;
}

interface ContentManagerInterface
{
    public function store(array $data): Content;
    public function update(int $id, array $data): Content;
    public function retrieve(int $id): Content;
    public function delete(int $id): bool;
}

interface TemplateInterface
{
    public function render(string $template, array $data = []): string;
    public function compile(string $template): CompiledTemplate;
}

interface AuthenticationInterface
{
    public function authenticate(array $credentials): AuthResult;
    public function validate(string $token): ?User;
    public function refresh(string $token): string;
}

interface ValidationInterface
{
    public function validate(array $data, array $rules = []): array;
    public function validateRequest(Request $request): array;
}

interface AuditInterface
{
    public function logAccess(Request $request): void;
    public function logAuthentication(string $email, string $status, array $context = []): void;
    public function logContentChange(string $action, int $contentId): void;
    public function logSecurityEvent(array $data): void;
}

interface MetricsInterface
{
    public function record(string $metric, float $value): void;
    public function increment(string $metric): void;
    public function gauge(string $metric, float $value): void;
}
