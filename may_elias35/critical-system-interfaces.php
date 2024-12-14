<?php

namespace App\Core\Contracts;

interface CriticalOperation
{
    public function validate(): bool;
    public function execute(): Result;
    public function verify(Result $result): bool;
}

interface SecurityManager
{
    public function validateAccess(array $context): bool;
    public function encryptSensitive(array $data): array;
    public function verifyIntegrity(array $data): bool;
}

interface SystemMonitor
{
    public function trackPerformance(string $operation): void;
    public function detectAnomalies(): array;
    public function reportStatus(): SystemStatus;
}

interface ContentRepository
{
    public function create(array $data): ContentResult;
    public function update(int $id, array $data): ContentResult;
    public function delete(int $id): bool;
    public function findWithSecurity(int $id): ?Content;
}

interface CacheManager
{
    public function remember(string $key, callable $callback);
    public function forget(string $key): void;
    public function tags(array $tags): static;
}

interface ValidationService 
{
    public function validateInput(array $data): bool;
    public function validateOutput($result): bool;
    public function validateState(): bool;
}

interface AuditLogger
{
    public function logOperation(string $operation, array $context): void;
    public function logSecurity(SecurityEvent $event): void;
    public function logPerformance(array $metrics): void;
}
