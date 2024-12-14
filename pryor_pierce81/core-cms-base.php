namespace App\Core;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Interfaces\{
    RepositoryInterface,
    SecurityInterface,
    ValidationInterface
};

abstract class BaseRepository implements RepositoryInterface 
{
    protected $model;
    protected $security;
    protected $validator;
    protected $cache;
    
    public function __construct(
        SecurityInterface $security,
        ValidationInterface $validator
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = Cache::tags($this->getCacheTags());
    }

    public function find($id)
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            config('cache.ttl'),
            fn() => $this->findModel($id)
        );
    }

    public function create(array $data)
    {
        return DB::transaction(function() use ($data) {
            $validated = $this->validator->validate($data);
            $this->security->validateCreate($validated);
            
            $model = $this->model->create($validated);
            $this->cache->tags($this->getCacheTags())->flush();
            
            return $model;
        });
    }

    public function update($id, array $data)
    {
        return DB::transaction(function() use ($id, $data) {
            $model = $this->findModel($id);
            $validated = $this->validator->validate($data);
            $this->security->validateUpdate($model, $validated);
            
            $model->update($validated);
            $this->cache->tags($this->getCacheTags())->flush();
            
            return $model->fresh();
        });
    }

    public function delete($id)
    {
        return DB::transaction(function() use ($id) {
            $model = $this->findModel($id);
            $this->security->validateDelete($model);
            
            $deleted = $model->delete();
            $this->cache->tags($this->getCacheTags())->flush();
            
            return $deleted;
        });
    }

    protected function findModel($id)
    {
        $model = $this->model->find($id);
        
        if (!$model) {
            throw new ModelNotFoundException(
                "Model not found with ID: $id"
            );
        }

        return $model;
    }

    abstract protected function getCacheKey(string $operation, ...$params): string;
    abstract protected function getCacheTags(): array;
}

abstract class BaseService
{
    protected $repository;
    protected $security;

    public function __construct(
        RepositoryInterface $repository,
        SecurityInterface $security  
    ) {
        $this->repository = $repository;
        $this->security = $security;
    }

    protected function executeSecure(callable $operation)
    {
        try {
            DB::beginTransaction();
            
            $this->security->validateContext();
            $result = $operation();
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError($e);
            throw $e;
        }
    }

    protected function handleError(\Exception $e)
    {
        Log::error('Operation failed', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

trait HasSecurity 
{
    protected function validatePermission(string $permission): void
    {
        if (!$this->security->hasPermission($permission)) {
            throw new UnauthorizedException(
                "Missing required permission: $permission"
            );
        }
    }

    protected function validateOwnership($model): void
    {
        if (!$this->security->validateOwner($model)) {
            throw new UnauthorizedException(
                'You do not have permission to modify this resource'
            );
        }
    }
}

interface ValidationInterface
{
    public function validate(array $data): array;
    public function validateContext(): void;
}

interface SecurityInterface 
{
    public function validateContext(): void;
    public function validateCreate(array $data): void;
    public function validateUpdate($model, array $data): void; 
    public function validateDelete($model): void;
    public function validateOwner($model): bool;
    public function hasPermission(string $permission): bool;
}
