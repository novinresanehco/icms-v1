<?php

namespace App\Core\Interfaces;

interface SecurityManagerInterface
{
    public function validateRequest(Request $request): ValidationResult;
    public function encrypt(string $data): string;
    public function decrypt(string $data): string;
}

interface ContentManagerInterface
{
    public function store(array $data): Content;
    public function retrieve(int $id): Content;
    public function update(int $id, array $data): Content;
    public function delete(int $id): bool;
}

interface TemplateEngineInterface
{
    public function render(string $template, array $data): string;
    public function compile(string $template): CompiledTemplate;
}

interface CacheManagerInterface
{
    public function remember(array $key, callable $callback, ?int $ttl = null): mixed;
    public function invalidate(array $key): void;
}

interface ValidationServiceInterface
{
    public function validate(array $data, array $rules): array;
    public function validateRequest(Request $request): bool;
}

interface AuditLoggerInterface
{
    public function logAccess(User $user, Request $request): void;
    public function logFailure(\Exception $e): void;
}

interface MetricsCollectorInterface
{
    public function record(array $metrics): void;
    public function collect(string $metric): array;
}

interface TokenManagerInterface
{
    public function generate(User $user): Token;
    public function validate(string $token): Token;
    public function revoke(string $token): void;
}
