namespace App\Core\Repository;

use App\Core\Interfaces\RepositoryInterface;
use App\Core\Exceptions\{
    ValidationException,
    SecurityException,
    SystemException
};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\{Cache, Log, DB};

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected array $searchable = [];
    protected array $sortable = [];
    protected int $cacheMinutes = 60;

    public function __construct(Model $model) 
    {
        $this->model = $model;
    }

    public function find(int $id, array $relations = []): ?Model
    {
        return Cache::remember(
            $this->getCacheKey('find', $id),
            $this->cacheMinutes,
            fn() => $this->model->with($relations)->find($id)
        );
    }

    public function create(array $data): Model
    {
        DB::beginTransaction();
        try {
            $this->validateData($data);
            
            $entity = $this->model->create($data);
            $this->clearCache();
            
            DB::commit();
            return $entity;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Repository create failed', [
                'model' => get_class($this->model),
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw new SystemException('Create operation failed', 0, $e);
        }
    }

    public function update(int $id, array $data): Model
    {
        DB::beginTransaction();
        try {
            $this->validateData($data);
            
            $entity = $this->model->findOrFail($id);
            $entity->update($data);
            $this->clearCache();
            
            DB::commit();
            return $entity->fresh();
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Repository update failed', [
                'model' => get_class($this->model),
                'id' => $id,
                'data' => $data,
                'error' => $e->getMessage()
            ]);
            throw new SystemException('Update operation failed', 0, $e);
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $entity = $this->model->findOrFail($id);
            $result = $entity->delete();
            $this->clearCache();
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Repository delete failed', [
                'model' => get_class($this->model),
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            throw new SystemException('Delete operation failed', 0, $e);
        }
    }

    protected function validateData(array $data): void
    {
        $validator = validator($data, $this->getValidationRules());
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
    }

    protected function clearCache(): void
    {
        $cacheKey = $this->getCacheKey('all');
        Cache::forget($cacheKey);
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            get_class($this->model),
            $operation,
            implode(':', $params)
        );
    }

    abstract protected function getValidationRules(): array;
}
