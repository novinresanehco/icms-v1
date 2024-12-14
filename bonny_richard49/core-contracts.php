<?php

namespace App\Core\Contracts;

interface SecurityManagerInterface
{
    public function validateOperation(array $context, array $data): void;
    public function executeSecureOperation(callable $operation, array $context): mixed;
    public function validateSecurityContext(array $context): void;
    public function verifyPermissions(array $required, array $provided): bool;
    public function validateDataIntegrity(array $data, string $hash): bool;
    public function encryptSensitiveData(array $data): array;
    public function decryptSensitiveData(array $data): array;
}

interface ValidationInterface
{
    public function validate(array $data, array $rules = []): array;
    public function validateWithContext(array $data, array $rules, array $context): array;
}

interface RepositoryInterface
{
    public function find(int $id, array $relations = []): ?Model;
    public function create(array $data): Model;
    public function update(int $id, array $data): Model;
    public function delete(int $id): bool;
}

interface ServiceInterface
{
    public function validate(array $data): array;
    public function execute(string $operation, array $data = []): mixed;
    public function getServiceIdentifier(): string;
}

interface CacheableInterface
{
    public function remember(string $key, callable $callback, int $ttl = null): mixed;
    public function invalidate(string $key): void;
    public function invalidatePattern(string $pattern): void;
}

interface EventInterface
{
    public function dispatch(string $event, array $payload = []): void;
    public function listen(string $event, callable $handler, bool $queue = false): void;
    public function subscribe(string $event, string $subscriber): void;
}

interface MonitoringInterface
{
    public function track(string $operationId, callable $operation): mixed;
    public function recordMetric(string $key, $value, array $tags = []): void;
    public function getMetrics(string $key = null, array $filters = []): array;
    public function alert(string $type, string $message, array $context = []): void;
}

interface PersistenceInterface
{
    public function store(array $data): array;
    public function find(int $id): ?array;
    public function update(int $id, array $data): array;
    public function delete(int $id): bool;
}

interface AuditInterface
{
    public function logSecurityEvent(string $event, array $context = []): void;
    public function logOperationEvent(string $event, array $context = []): void;
    public function logValidationEvent(string $event, array $context = []): void;
}

interface QueueableOperationInterface
{
    public function getQueueName(): string;
    public function getQueuePriority(): int;
    public function getRetryCount(): int;
    public function shouldBeQueued(): bool;
}

interface TransactionInterface
{
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
    public function isTransactionActive(): bool;
}

abstract class BaseException extends \Exception
{
    protected array $context = [];
    
    public function __construct(
        string $message = "",
        array $context = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }
    
    public function getContext(): array
    {
        return $this->context;
    }
}

class SecurityException extends BaseException {}
class ValidationException extends BaseException {}
class RepositoryException extends BaseException {}
class ServiceException extends BaseException {}
class CacheException extends BaseException {}
class EventException extends BaseException {}
class MonitoringException extends BaseException {}
class PersistenceException extends BaseException {}
class TransactionException extends BaseException {}

trait SecurityAwareTrait
{
    protected SecurityManagerInterface $security;
    
    public function setSecurityManager(SecurityManagerInterface $security): void
    {
        $this->security = $security;
    }
    
    protected function executeSecure(callable $operation, array $context): mixed
    {
        return $this->security->executeSecureOperation($operation, $context);
    }
}

trait ValidatableTrait
{
    protected ValidationInterface $validator;
    
    public function setValidator(ValidationInterface $validator): void
    {
        $this->validator = $validator;
    }
    
    protected function validateData(array $data, array $rules): array
    {
        return $this->validator->validate($data, $rules);
    }
}

trait CacheableTrait
{
    protected CacheableInterface $cache;
    
    public function setCache(CacheableInterface $cache): void
    {
        $this->cache = $cache;
    }
    
    protected function rememberCache(string $key, callable $callback, int $ttl = null): mixed
    {
        return $this->cache->remember($key, $callback, $ttl);
    }
}

trait MonitoredTrait
{
    protected MonitoringInterface $monitor;
    
    public function setMonitor(MonitoringInterface $monitor): void
    {
        $this->monitor = $monitor;
    }
    
    protected function trackOperation(string $operationId, callable $operation): mixed
    {
        return $this->monitor->track($operationId, $operation);
    }
}
