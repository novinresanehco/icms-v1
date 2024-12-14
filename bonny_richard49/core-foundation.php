// Core Protection Layer
namespace App\Core\Foundation;

abstract class CriticalOperation
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditLogger $logger;
    protected BackupService $backup;

    /**
     * Execute critical operation with full protection
     */
    final public function execute(array $data): OperationResult
    {
        DB::beginTransaction();
        $backupId = $this->backup->createPoint();
        
        try {
            $this->validatePreConditions($data);
            $result = $this->executeOperation($data);
            $this->validateResult($result);
            
            DB::commit();
            $this->logger->logSuccess($this->getOperationType(), $data, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->backup->restore($backupId);
            $this->handleFailure($e, $data);
            throw $e;
        }
    }

    /**
     * Validate operation pre-conditions
     */
    protected function validatePreConditions(array $data): void
    {
        if (!$this->security->validateAccess($this->getRequiredPermissions())) {
            throw new SecurityException('Access denied');
        }

        if (!$this->validator->validate($data, $this->getValidationRules())) {
            throw new ValidationException('Invalid data');
        }
    }

    abstract protected function executeOperation(array $data): OperationResult;
    abstract protected function getOperationType(): string;
    abstract protected function getRequiredPermissions(): array;
    abstract protected function getValidationRules(): array;
}

interface SecurityManager
{
    public function validateAccess(array $permissions): bool;
    public function validateRequest(Request $request): void;
    public function getSecurityContext(): SecurityContext;
}

interface ValidationService
{
    public function validate(array $data, array $rules): bool;
    public function validateOperation(string $operation, array $data): bool;
    public function validateResult(OperationResult $result): bool;
}

// Core Repository Layer
abstract class Repository
{
    protected EntityManager $em;
    protected CacheManager $cache;
    protected ValidationService $validator;

    public function find(int $id): ?Entity
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            function() use ($id) {
                return $this->em->find($id);
            }
        );
    }

    public function create(array $data): Entity
    {
        $this->validator->validate($data, $this->getCreationRules());
        $entity = $this->em->create($data);
        $this->cache->invalidatePrefix($this->getCachePrefix());
        return $entity;
    }

    public function update(int $id, array $data): Entity
    {
        $this->validator->validate($data, $this->getUpdateRules());
        $entity = $this->em->update($id, $data);
        $this->cache->invalidate($this->getCacheKey('find', $id));
        return $entity;
    }

    abstract protected function getCachePrefix(): string;
    abstract protected function getCreationRules(): array;
    abstract protected function getUpdateRules(): array;
}

// Core Service Layer
abstract class Service
{
    protected Repository $repository;
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected EventDispatcher $events;

    protected function executeInTransaction(callable $operation): mixed
    {
        DB::beginTransaction();
        
        try {
            $result = $operation();
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function dispatchEvents(array $events): void
    {
        foreach ($events as $event) {
            $this->events->dispatch($event);
        }
    }
}

// Core Event System
abstract class DomainEvent
{
    protected string $aggregateId;
    protected array $data;
    protected \DateTimeImmutable $occurredOn;

    public function __construct(string $aggregateId, array $data = [])
    {
        $this->aggregateId = $aggregateId;
        $this->data = $data;
        $this->occurredOn = new \DateTimeImmutable();
    }

    abstract public function getEventName(): string;
    abstract public function getAggregateType(): string;
}

class EventDispatcher
{
    private array $listeners = [];

    public function addListener(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(DomainEvent $event): void
    {
        $eventName = $event->getEventName();
        
        foreach ($this->listeners[$eventName] ?? [] as $listener) {
            $listener($event);
        }
    }
}
