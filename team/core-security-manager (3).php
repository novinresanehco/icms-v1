<?php

namespace App\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\{SecurityInterface, LoggerInterface};
use App\Core\Exceptions\{SecurityException, ValidationException};

class SecurityManager implements SecurityInterface
{
    private CacheManager $cache;
    private LoggerInterface $logger;
    private ValidationService $validator;
    private EncryptionService $encryption;
    
    public function __construct(
        CacheManager $cache,
        LoggerInterface $logger,
        ValidationService $validator, 
        EncryptionService $encryption
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->validator = $validator;
        $this->encryption = $encryption;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        // Start transaction and monitoring
        DB::beginTransaction();
        $startTime = microtime(true);

        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            $this->logSuccess($context);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        } finally {
            $this->recordMetrics($startTime);
        }
    }

    protected function validateOperation(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->validateSecurity($context)) {
            throw new SecurityException('Security validation failed');
        }
    }

    protected function executeWithMonitoring(callable $operation): mixed
    {
        $result = $operation();

        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }

        return $result;
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }
    }

    protected function handleFailure(\Exception $e, array $context): void
    {
        $this->logger->logError('Operation failed', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function logSuccess(array $context): void
    {
        $this->logger->logInfo('Operation completed successfully', [
            'context' => $context
        ]);
    }

    protected function recordMetrics(float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->logger->logMetric('operation_duration', $duration);
    }
}

abstract class CriticalOperation
{
    protected SecurityManager $security;
    protected LoggerInterface $logger;

    public function __construct(SecurityManager $security, LoggerInterface $logger)
    {
        $this->security = $security;
        $this->logger = $logger;
    }

    public function execute(array $context = []): mixed
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->performOperation($context),
            $context
        );
    }

    abstract protected function performOperation(array $context): mixed;
}

class ContentManager extends CriticalOperation
{
    protected ContentRepository $repository;

    public function __construct(
        SecurityManager $security,
        LoggerInterface $logger,
        ContentRepository $repository
    ) {
        parent::__construct($security, $logger);
        $this->repository = $repository;
    }

    protected function performOperation(array $context): mixed
    {
        return match($context['action']) {
            'create' => $this->repository->create($context['data']),
            'update' => $this->repository->update($context['id'], $context['data']),
            'delete' => $this->repository->delete($context['id']),
            default => throw new \InvalidArgumentException('Invalid content operation')
        };
    }
}

interface ContentRepository
{
    public function create(array $data): Content;
    public function update(string $id, array $data): Content;
    public function delete(string $id): bool;
    public function find(string $id): ?Content;
}

class DatabaseContentRepository implements ContentRepository
{
    protected string $table = 'contents';
    
    public function create(array $data): Content
    {
        $id = DB::table($this->table)->insertGetId($data);
        return $this->find($id);
    }
    
    public function update(string $id, array $data): Content
    {
        DB::table($this->table)->where('id', $id)->update($data);
        return $this->find($id);
    }
    
    public function delete(string $id): bool
    {
        return DB::table($this->table)->delete($id) > 0;
    }
    
    public function find(string $id): ?Content
    {
        $data = DB::table($this->table)->find($id);
        return $data ? new Content($data) : null;
    }
}
