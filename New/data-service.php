<?php

namespace App\Core\Data;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected CacheService $cache;
    protected ValidationService $validator;
    protected SecurityService $security;
    protected MonitoringService $monitor;

    public function __construct(
        Model $model,
        CacheService $cache,
        ValidationService $validator,
        SecurityService $security,
        MonitoringService $monitor
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->security = $security;
        $this->monitor = $monitor;
    }

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->model->find($id)
        );
    }

    public function create(array $data): Model
    {
        return DB::transaction(function() use ($data) {
            $validated = $this->validateData($data);
            $protected = $this->security->encryptSensitiveData($validated);
            
            $model = $this->model->create($protected);
            $this->cache->tags($this->getCacheTags())->flush();
            
            return $model;
        });
    }

    public function update(int $id, array $data): Model
    {
        return DB::transaction(function() use ($id, $data) {
            $model = $this->model->findOrFail($id);
            $validated = $this->validateData($data);
            $protected = $this->security->encryptSensitiveData($validated);
            
            $model->update($protected);
            $this->cache->tags($this->getCacheTags())->flush();
            
            return $model;
        });
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $model = $this->model->findOrFail($id);
            $result = $model->delete();
            
            $this->cache->tags($this->getCacheTags())->flush();
            return $result;
        });
    }

    protected function validateData(array $data): array
    {
        return $this->validator->validate($data, $this->getValidationRules());
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $operation,
            implode(':', $params)
        );
    }

    protected function getCacheTags(): array
    {
        return [$this->model->getTable()];
    }

    abstract protected function getValidationRules(): array;
    abstract protected function getSensitiveFields(): array;
}

class TransactionManager implements TransactionInterface
{
    private MonitoringService $monitor;
    private SecurityService $security;

    public function __construct(
        MonitoringService $monitor,
        SecurityService $security
    ) {
        $this->monitor = $monitor;
        $this->security = $security;
    }

    public function execute(callable $operation): mixed
    {
        $context = new TransactionContext();

        try {
            DB::beginTransaction();
            $this->monitor->startTransaction($context);

            $result = $operation();

            $this->monitor->validateTransaction($context);
            DB::commit();

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleTransactionFailure($e, $context);
            throw $e;
        } finally {
            $this->monitor->endTransaction($context);
        }
    }

    protected function handleTransactionFailure(\Exception $e, TransactionContext $context): void
    {
        $this->monitor->logTransactionFailure($e, $context);
        $this->security->handleSecurityEvent('transaction_failure', $context);
    }
}

interface RepositoryInterface
{
    public function find(int $id): ?Model;
    public function create(array $data): Model;
    public function update(int $id, array $data): Model;
    public function delete(int $id): bool;
}

interface TransactionInterface
{
    public function execute(callable $operation): mixed;
}