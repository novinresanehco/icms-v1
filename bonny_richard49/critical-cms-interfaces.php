<?php

namespace App\Core\Contracts;

interface CriticalServiceInterface
{
    public function executeOperation(CriticalOperation $operation): OperationResult;
}

interface CriticalContentInterface
{
    public function store(array $data): Content;
    public function update(int $id, array $data): Content;
    public function delete(int $id): bool;
}

interface CriticalUserInterface
{
    public function authenticate(array $credentials): User;
    public function authorizeAccess(User $user, string $resource): bool;
    public function create(array $data): User;
}

interface CriticalCacheInterface
{
    public function remember(string $key, \Closure $callback, ?int $ttl = null);
    public function forget(string $key): bool;
    public function flush(): bool;
}

interface CriticalValidationInterface
{
    public function validate(array $data): array;
    public function validateResult($result): bool;
}

interface CriticalSecurityInterface
{
    public function validateOperation(CriticalOperation $operation): void;
    public function encrypt($data);
    public function decrypt($data);
    public function hashKey(string $key): string;
    public function hashCredentials(array $credentials): array;
}

interface CriticalRepositoryInterface
{
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id): bool;
    public function find(int $id);
    public function findOrFail(int $id);
}

interface CriticalOperationInterface
{
    public function execute();
    public function getCacheKey(): string;
}
