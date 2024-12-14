<?php

namespace App\Core\Foundation;

/**
 * Core interface defining foundational requirements for all CMS components
 */
interface CoreComponentInterface
{
    /**
     * Validates component integrity and security requirements
     *
     * @throws ComponentValidationException
     */
    public function validateIntegrity(): ValidationResult;
    
    /**
     * Initializes component with required dependencies and configuration
     *
     * @param array $dependencies Injected dependencies
     * @throws ComponentInitializationException
     */
    public function initialize(array $dependencies = []): void;
    
    /**
     * Retrieves component's current health status
     */
    public function getHealthStatus(): ComponentHealth;
}

/**
 * Base repository implementing core data access patterns with security and caching
 */
abstract class BaseRepository
{
    protected Model $model;
    protected CacheManager $cache;
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected EventDispatcher $events;

    public function __construct(
        Model $model,
        CacheManager $cache,
        SecurityManager $security,
        ValidationService $validator,
        EventDispatcher $events
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
        $this->events = $events;
    }

    /**
     * Creates new resource with validation and security checks
     *
     * @throws ValidationException|SecurityException|RepositoryException
     */
    public function create(array $data): Model
    {
        // Validate input
        $this->validator->validate($data, $this->getValidationRules());
        
        // Security check
        $this->security->validateOperation('create', $this->model);

        return DB::transaction(function() use ($data) {
            try {
                $model = $this->model->create($data);
                
                // Clear relevant caches
                $this->cache->tags($this->getCacheTags())->flush();
                
                // Dispatch event
                $this->events->dispatch(new ResourceCreated($model));
                
                return $model;
            } catch (\Exception $e) {
                throw new RepositoryException("Failed to create resource: {$e->getMessage()}", 0, $e);
            }
        });
    }

    /**
     * Retrieves resource by ID with caching and security checks
     *
     * @throws SecurityException|RepositoryException
     */
    protected function find(int $id): ?Model
    {
        $cacheKey = $this->getCacheKey('find', $id);
        
        return $this->cache->remember($cacheKey, config('cache.ttl'), function() use ($id) {
            try {
                $model = $this->model->find($id);
                
                if ($model) {
                    $this->security->validateAccess('read', $model);
                }
                
                return $model;
            } catch (\Exception $e) {
                throw new RepositoryException("Failed to retrieve resource: {$e->getMessage()}", 0, $e);
            }
        });
    }

    abstract protected function getValidationRules(): array;
    abstract protected function getCacheTags(): array;
    abstract protected function getCacheKey(string $operation, ...$params): string;
}

/**
 * Base service implementing core business logic patterns
 */
abstract class BaseService
{
    protected RepositoryInterface $repository;
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected EventDispatcher $events;
    protected MetricsCollector $metrics;

    /**
     * Executes operation with transaction, validation, and security checks
     *
     * @throws ValidationException|SecurityException|ServiceException
     */
    protected function executeOperation(string $operation, callable $callback, array $data = []): mixed
    {
        // Validate operation input
        if (!empty($data)) {
            $this->validator->validate($data, $this->getValidationRules($operation));
        }

        // Security check
        $this->security->validateOperation($operation, $this->getResourceType());

        // Start metrics collection
        $timer = $this->metrics->startTimer($operation);

        return DB::transaction(function() use ($callback, $operation, $timer) {
            try {
                $result = $callback();
                
                // Record metrics
                $this->metrics->endTimer($timer);
                $this->metrics->incrementOperation($operation);
                
                // Dispatch event
                $this->events->dispatch(new OperationCompleted($operation, $result));
                
                return $result;
            } catch (\Exception $e) {
                $this->metrics->incrementError($operation);
                throw new ServiceException("Operation failed: {$e->getMessage()}", 0, $e);
            }
        });
    }

    abstract protected function getValidationRules(string $operation): array;
    abstract protected function getResourceType(): string;
}
