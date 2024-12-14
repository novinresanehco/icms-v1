namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;

abstract class BaseRepository
{
    protected Model $model;
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected array $defaultRelations = [];
    protected int $cacheTimeout = 3600;
    
    public function __construct(
        Model $model,
        SecurityManager $security,
        ValidationService $validator
    ) {
        $this->model = $model;
        $this->security = $security;
        $this->validator = $validator;
    }

    protected function executeSecure(callable $operation)
    {
        try {
            $this->security->validateOperation();
            
            return DB::transaction(function() use ($operation) {
                $result = $operation();
                $this->security->validateResult($result);
                return $result;
            });
            
        } catch (SecurityException $e) {
            $this->security->handleFailure($e);
            throw $e;
        }
    }
    
    public function find(int $id): ?Model
    {
        return $this->executeSecure(function() use ($id) {
            return Cache::remember(
                $this->getCacheKey('find', $id),
                $this->cacheTimeout,
                fn() => $this->model
                    ->with($this->defaultRelations)
                    ->findOrFail($id)
            );
        });
    }

    public function create(array $data): Model 
    {
        return $this->executeSecure(function() use ($data) {
            $validated = $this->validator->validate($data);
            
            $model = $this->model->create($validated);
            $this->clearModelCache($model);
            
            return $model->fresh($this->defaultRelations);
        });
    }

    public function update(int $id, array $data): Model
    {
        return $this->executeSecure(function() use ($id, $data) {
            $model = $this->find($id);
            $validated = $this->validator->validate($data);
            
            $model->update($validated);
            $this->clearModelCache($model);
            
            return $model->fresh($this->defaultRelations);
        });
    }

    public function delete(int $id): bool
    {
        return $this->executeSecure(function() use ($id) {
            $model = $this->find($id);
            $this->clearModelCache($model);
            return $model->delete();
        });
    }

    protected function clearModelCache(Model $model): void
    {
        Cache::tags($this->getCacheTags($model))->flush();
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

    protected function getCacheTags(Model $model): array
    {
        return [
            $this->model->getTable(),
            get_class($model) . ':' . $model->getKey()
        ];
    }

    abstract protected function getValidationRules(): array;
}

class ContentRepository extends BaseRepository 
{
    protected array $defaultRelations = ['category', 'tags', 'author'];

    protected function getValidationRules(): array 
    {
        return [
            'title' => 'required|max:200',
            'content' => 'required',
            'status' => 'required|in:draft,published',
            'category_id' => 'required|exists:categories,id',
            'tags' => 'array',
            'tags.*' => 'exists:tags,id'
        ];
    }

    public function publish(int $id): Model
    {
        return $this->executeSecure(function() use ($id) {
            $content = $this->find($id);
            
            $content->update([
                'status' => 'published',
                'published_at' => now()
            ]);
            
            $this->clearModelCache($content);
            return $content->fresh($this->defaultRelations);
        });
    }

    public function getPublished(array $filters = []): Collection
    {
        return $this->executeSecure(function() use ($filters) {
            $cacheKey = $this->getCacheKey('published', serialize($filters));
            
            return Cache::remember($cacheKey, $this->cacheTimeout, function() use ($filters) {
                return $this->model
                    ->with($this->defaultRelations)
                    ->where('status', 'published')
                    ->when($filters['category_id'] ?? null, fn($q) => 
                        $q->where('category_id', $filters['category_id'])
                    )
                    ->latest('published_at')
                    ->get();
            });
        });
    }
}
