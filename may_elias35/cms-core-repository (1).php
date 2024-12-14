namespace App\Core\Repository;

class ContentRepository implements RepositoryInterface
{
    private Model $model;
    private CacheManager $cache;
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditLogger $logger;
    private MetricsCollector $metrics;

    public function __construct(
        Model $model,
        CacheManager $cache,
        SecurityManager $security,
        ValidationService $validator,
        AuditLogger $logger,
        MetricsCollector $metrics
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function find(int $id): ?Model
    {
        $startTime = microtime(true);
        $cacheKey = $this->getCacheKey('find', $id);

        try {
            return $this->cache->remember($cacheKey, function() use ($id) {
                $this->security->validateAccess('content.read', $id);
                
                $result = $this->model->find($id);
                
                if ($result) {
                    $this->validateData($result->toArray());
                    $this->logger->logAccess('read', $id);
                }
                
                return $result;
            });
        } finally {
            $this->metrics->recordOperation(
                'repository.find',
                microtime(true) - $startTime
            );
        }
    }

    public function create(array $data): Model
    {
        $startTime = microtime(true);
        
        DB::beginTransaction();
        try {
            $this->security->validateAccess('content.create');
            
            $validated = $this->validator->validate($data, $this->getValidationRules());
            
            $result = $this->model->create($validated);
            
            $this->cache->tags(['content'])->flush();
            
            $this->logger->logOperation('create', $result->id, $validated);
            
            DB::commit();
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logError('create', $e);
            throw $e;
        } finally {
            $this->metrics->recordOperation(
                'repository.create',
                microtime(true) - $startTime
            );
        }
    }

    public function update(int $id, array $data): Model
    {
        $startTime = microtime(true);
        
        DB::beginTransaction();
        try {
            $this->security->validateAccess('content.update', $id);
            
            $model = $this->model->findOrFail($id);
            
            $validated = $this->validator->validate($data, $this->getValidationRules());
            
            $result = tap($model)->update($validated);
            
            $this->cache->tags(['content'])->flush();
            
            $this->logger->logOperation('update', $id, $validated);
            
            DB::commit();
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logError('update', $e);
            throw $e;
        } finally {
            $this->metrics->recordOperation(
                'repository.update',
                microtime(true) - $startTime
            );
        }
    }

    public function delete(int $id): bool
    {
        $startTime = microtime(true);
        
        DB::beginTransaction();
        try {
            $this->security->validateAccess('content.delete', $id);
            
            $model = $this->model->findOrFail($id);
            
            $result = $model->delete();
            
            $this->cache->tags(['content'])->flush();
            
            $this->logger->logOperation('delete', $id);
            
            DB::commit();
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logError('delete', $e);
            throw $e;
        } finally {
            $this->metrics->recordOperation(
                'repository.delete',
                microtime(true) - $startTime
            );
        }
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            'content:%s:%s',
            $operation,
            implode(':', $params)
        );
    }

    protected function getValidationRules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'status' => 'required|in:draft,published',
            'author_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id',
            'tags' => 'array',
            'tags.*' => 'exists:tags,id',
            'published_at' => 'nullable|date'
        ];
    }

    protected function validateData(array $data): void
    {
        $this->validator->validate($data, $this->getValidationRules());
    }
}
