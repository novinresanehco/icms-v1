<?php

namespace App\Core\Service;

use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityManager;
use App\Core\Repository\RepositoryInterface;
use App\Core\Support\{EventDispatcher, AuditLogger};
use App\Core\Exceptions\{ServiceException, ValidationException};

abstract class BaseService implements ServiceInterface
{
    protected RepositoryInterface $repository;
    protected SecurityManager $security;
    protected EventDispatcher $events;
    protected AuditLogger $logger;

    public function __construct(
        RepositoryInterface $repository,
        SecurityManager $security,
        EventDispatcher $events,
        AuditLogger $logger
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->events = $events;
        $this->logger = $logger;
    }

    protected function executeSecureOperation(string $operation, array $data, callable $action): mixed
    {
        // Create operation context
        $context = $this->createOperationContext($operation, $data);
        
        // Execute through security layer
        return $this->security->executeCriticalOperation(
            new ServiceOperation($context, $action)
        );
    }

    protected function executeInTransaction(callable $operation)
    {
        DB::beginTransaction();
        
        try {
            // Execute operation
            $result = $operation();
            
            // Commit transaction
            DB::commit();
            
            // Log success
            $this->logger->info('Operation completed successfully');
            
            return $result;
            
        } catch (\Exception $e) {
            // Rollback transaction
            DB::rollBack();
            
            // Log failure
            $this->logger->error('Operation failed', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new ServiceException(
                'Service operation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    protected function dispatchEvents(array $events): void
    {
        foreach ($events as $event) {
            $this->events->dispatch($event);
        }
    }

    protected function validateOperation(string $operation, array $data): array
    {
        try {
            return $this->getValidator()->validate(
                $data,
                $this->getValidationRules($operation)
            );
        } catch (ValidationException $e) {
            throw new ServiceException(
                'Validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    protected function createOperationContext(string $operation, array $data): array
    {
        return [
            'operation' => $operation,
            'data' => $data,
            'service' => static::class,
            'timestamp' => now(),
            'user' => auth()->user(),
        ];
    }

    protected function authorize(string $ability, mixed ...$arguments): void
    {
        if (!$this->security->authorize($ability, ...$arguments)) {
            throw new UnauthorizedException();
        }
    }

    protected function logOperation(string $operation, array $context = []): void
    {
        $this->logger->info("Service operation: {$operation}", $context);
    }

    abstract protected function getValidator(): ValidatorInterface;
    abstract protected function getValidationRules(string $operation): array;
}

class ServiceOperation implements CriticalOperation
{
    private array $context;
    private callable $action;

    public function __construct(array $context, callable $action)
    {
        $this->context = $context;
        $this->action = $action;
    }

    public function execute(): mixed
    {
        return call_user_func($this->action);
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getValidationRules(): array
    {
        return [];
    }

    public function getSecurityRequirements(): array
    {
        return [
            'authentication' => true,
            'authorization' => true,
            'audit_logging' => true
        ];
    }

    public function requiresIpWhitelist(): bool
    {
        return false;
    }
}
