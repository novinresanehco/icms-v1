<?php
namespace App\Core\Interfaces;

interface CriticalOperationInterface {
    public function executeWithProtection(array $params): mixed;
}

interface SecurityManagerInterface {
    public function validateContext(array $context): void;
    public function enforceEncryption(mixed $data): string;
}

interface ContentManagerInterface {
    public function create(array $data): mixed;
    public function update(int $id, array $data): mixed;
    public function delete(int $id): bool;
    public function retrieve(int $id): mixed;
}

interface SystemManagerInterface {
    public function validateSystemState(): void;
    public function logPerformanceMetrics(string $operation, float $executionTime): void;
}

interface ValidationEngineInterface {
    public function validateInput(array $data): void;
    public function validateOutput($result): void;
}

interface AuditLoggerInterface {
    public function logSuccess(array $params, $result, float $executionTime): void;
    public function logFailure(\Exception $e, array $params): void;
}

interface CacheManagerInterface {
    public function store(string $key, mixed $value, ?int $ttl = null): void;
    public function retrieve(string $key): mixed;
    public function invalidate(string $key): void;
}

interface MetricsCollectorInterface {
    public function record(string $operation, array $metrics): void;
    public function retrieve(string $operation): array;
}

interface ConfigManagerInterface {
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
}
