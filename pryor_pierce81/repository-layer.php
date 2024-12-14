namespace App\Core\Repository;

class BaseRepository implements RepositoryInterface 
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected ValidatorService $validator;
    protected MetricsCollector $metrics;
    protected Model $model;
    protected array $criteria = [];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidatorService $validator,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->model = $this->resolveModel();
    }

    public function find(int $id): ?Model
    {
        $startTime = microtime(true);
        $cacheKey = $this->getCacheKey('find', $id);

        try {
            return $this->cache->remember($cacheKey, 3600, function () use ($id) {
                return $this->security->executeCriticalOperation(
                    new FindOperation($id, $this->model),
                    SecurityContext::fromRequest()
                );
            });
        } finally {
            $this->metrics->timing(
                "repository.find.duration",
                microtime(true) - $startTime
            );
        }
    }

    public function findBy(array $criteria): Collection
    {
        return $this->security->executeCriticalOperation(
            new FindByOperation(
                $criteria,
                $this->model,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function create(array $data): Model
    {
        $validated = $this->validator->validate($data, $this->getValidationRules());

        return $this->security->executeCriticalOperation(
            new CreateOperation(
                $validated,
                $this->model,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function update(int $id, array $data): Model
    {
        $validated = $this->validator->validate($data, $this->getValidationRules());

        return $this->security->executeCriticalOperation(
            new UpdateOperation(
                $id,
                $validated,
                $this->model,
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
                $this->model,
                $this->cache
            ),
            SecurityContext::fromRequest()
        );
    }

    public function paginate(int $page = 1, int $perPage = 15): PaginatedResult
    {
        $cacheKey = $this->getCacheKey('paginate', $page, $perPage);

        return $this->cache->remember($cacheKey, 3600, function () use ($page, $perPage) {
            return $this->security->executeCriticalOperation(
                new PaginateOperation(
                    $page,
                    $perPage,
                    $this->model,
                    $this->criteria
                ),
                SecurityContext::fromRequest()
            );
        });
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $operation,
            md5(serialize($params))
        );
    }

    protected function clearModelCache(Model $model): void
    {
        $this->cache->tags([
            $this->model->getTable(),
            sprintf('%s.%s', $this->model->getTable(), $model->id)
        ])->flush();
    }

    protected function getValidationRules(): array
    {
        return $this->cache->remember(
            'validation_rules:' . $this->model->getTable(),
            3600,
            fn() => $this->model->getValidationRules()
        );
    }

    public function pushCriteria($criteria): self
    {
        $this->criteria[] = $criteria;
        return $this;
    }

    public function popCriteria(): self
    {
        array_pop($this->criteria);
        return $this;
    }

    protected function applyCriteria(): void
    {
        foreach ($this->criteria as $criteria) {
            $this->model = $criteria->apply($this->model);
        }
    }

    protected function resolveModel(): Model
    {
        $model = app($this->model());

        if (!$model instanceof Model) {
            throw new RepositoryException('Class must be instance of Model');
        }

        return $model;
    }

    abstract protected function model(): string;
}
