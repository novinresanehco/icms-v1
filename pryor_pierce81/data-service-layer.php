namespace App\Core\Services;

class DataService implements DataServiceInterface
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $audit;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        Repository $repository,
        CacheManager $cache,
        ValidationService $validator,
        AuditLogger $audit,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->metrics = $metrics;
    }

    public function create(array $data): Entity
    {
        return $this->security->executeCriticalOperation(
            new CreateOperation(
                $this->validateData($data),
                $this->repository,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function update(int $id, array $data): Entity
    {
        return $this->security->executeCriticalOperation(
            new UpdateOperation(
                $id,
                $this->validateData($data),
                $this->repository,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteOperation(
                $id,
                $this->repository,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function find(int $id): ?Entity
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            3600,
            fn() => $this->repository->find($id)
        );
    }

    public function findBy(array $criteria): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('findBy', $criteria),
            3600,
            fn() => $this->repository->findBy($criteria)
        );
    }

    public function paginate(int $page, int $perPage): PaginatedResult
    {
        return $this->cache->remember(
            $this->getCacheKey('paginate', $page, $perPage),
            3600,
            fn() => $this->repository->paginate($page, $perPage)
        );
    }

    private function validateData(array $data): array
    {
        $startTime = microtime(true);

        try {
            $validated = $this->validator->validate($data, $this->getValidationRules());
            
            $this->metrics->timing(
                'validation.duration',
                microtime(true) - $startTime
            );

            return $validated;
        } catch (ValidationException $e) {
            $this->metrics->increment('validation.failures');
            throw $e;
        }
    }

    private function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->repository->getEntityType(),
            $operation,
            md5(serialize($params))
        );
    }

    private function clearEntityCache(Entity $entity): void
    {
        $this->cache->tags([
            $this->repository->getEntityType(),
            sprintf('%s.%s', $this->repository->getEntityType(), $entity->getId())
        ])->flush();
    }

    private function getValidationRules(): array
    {
        return $this->cache->remember(
            'validation_rules:' . $this->repository->getEntityType(),
            3600,
            fn() => $this->repository->getValidationRules()
        );
    }

    public function transaction(callable $callback)
    {
        return DB::transaction(function () use ($callback) {
            $result = $callback($this);
            $this->audit->logOperationEvent(
                new TransactionEvent($this->repository->getEntityType())
            );
            return $result;
        });
    }

    public function withCriteria(array $criteria): self
    {
        $this->repository->pushCriteria($criteria);
        return $this;
    }
}
