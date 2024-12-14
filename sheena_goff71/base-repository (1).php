namespace App\Core\Repository;

use App\Core\Security\SecurityManager;
use Illuminate\Database\Eloquent\{Model, Builder, Collection};
use Illuminate\Support\Facades\{DB, Cache};

abstract class BaseRepository implements RepositoryInterface
{
    protected SecurityManager $security;
    protected Model $model;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected QueryBuilder $queryBuilder;
    protected AuditLogger $logger;

    public function __construct(
        SecurityManager $security,
        Model $model,
        CacheManager $cache,
        ValidationService $validator,
        QueryBuilder $queryBuilder,
        AuditLogger $logger
    ) {
        $this->security = $security;
        $this->model = $model;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->queryBuilder = $queryBuilder;
        $this->logger = $logger;
    }

    public function find(int $id, array $relations = []): ?Model
    {
        return $this->security->executeCriticalOperation(
            new FindOperation($id),
            new SecurityContext(['model' => $this->model->getTable()]),
            function() use ($id, $relations) {
                return $this->cache->remember(
                    $this->getCacheKey('find', $id),
                    function() use ($id, $relations) {
                        $query = $this->model->newQuery();
                        
                        if (!empty($relations)) {
                            $query->with($relations);
                        }
                        
                        return $query->find($id);
                    }
                );
            }
        );
    }

    public function findOrFail(int $id, array $relations = []): Model
    {
        $result = $this->find($id, $relations);
        
        if (!$result) {
            throw new ModelNotFoundException(
                "Model {$this->model->getTable()} not found for ID: {$id}"
            );
        }
        
        return $result;
    }

    public function create(array $data): Model
    {
        return $this->security->executeCriticalOperation(
            new CreateOperation($data),
            new SecurityContext(['model' => $this->model->getTable()]),
            function() use ($data) {
                $validated = $this->validator->validateForCreation($data);
                
                return DB::transaction(function() use ($validated) {
                    $model = $this->model->create($validated);
                    $this->logger->logCreation($model);
                    $this->clearModelCache();
                    
                    return $model;
                });
            }
        );
    }

    public function update(int $id, array $data): Model
    {
        return $this->security->executeCriticalOperation(
            new UpdateOperation($id, $data),
            new SecurityContext(['model' => $this->model->getTable()]),
            function() use ($id, $data) {
                $model = $this->findOrFail($id);
                $validated = $this->validator->validateForUpdate($data);
                
                return DB::transaction(function() use ($model, $validated) {
                    $model->update($validated);
                    $this->logger->logUpdate($model);
                    $this->clearModelCache($model->id);
                    
                    return $model->fresh();
                });
            }
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            new DeleteOperation($id),
            new SecurityContext(['model' => $this->model->getTable()]),
            function() use ($id) {
                $model = $this->findOrFail($id);
                
                return DB::transaction(function() use ($model) {
                    $result = $model->delete();
                    $this->logger->logDeletion($model);
                    $this->clearModelCache($model->id);
                    
                    return $result;
                });
            }
        );
    }

    public function paginate(array $criteria = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->security->executeCriticalOperation(
            new PaginateOperation($criteria),
            new SecurityContext(['model' => $this->model->getTable()]),
            function() use ($criteria, $perPage) {
                return $this->cache->remember(
                    $this->getCacheKey('paginate', $criteria, $perPage),
                    function() use ($criteria, $perPage) {
                        return $this->buildQuery($criteria)->paginate($perPage);
                    }
                );
            }
        );
    }

    protected function buildQuery(array $criteria): Builder
    {
        $query = $this->model->newQuery();
        
        return $this->queryBuilder
            ->applyCriteria($query, $criteria)
            ->applyOrdering($criteria)
            ->applyIncludes($criteria);
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        $key = sprintf(
            '%s:%s:%s',
            $this->model->getTable(),
            $operation,
            md5(serialize($params))
        );

        return hash('sha256', $key);
    }

    protected function clearModelCache(?int $id = null): void
    {
        if ($id) {
            $this->cache->forget($this->getCacheKey('find', $id));
        }
        
        $this->cache->tags([$this->model->getTable()])->flush();
    }

    protected function validateData(array $data, string $operation): array
    {
        return match($operation) {
            'create' => $this->validator->validateForCreation($data),
            'update' => $this->validator->validateForUpdate($data),
            default => throw new InvalidOperationException("Unknown operation: {$operation}")
        };
    }

    protected function logOperation(string $operation, Model $model, ?array $data = null): void
    {
        $this->logger->log(
            $operation,
            $this->model->getTable(),
            [
                'id' => $model->id,
                'data' => $data,
                'changes' => $model->getDirty()
            ]
        );
    }
}
