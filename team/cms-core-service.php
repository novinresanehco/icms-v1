namespace App\Core\Services;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Repository\ContentRepository;
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\{ValidationException, SecurityException};

class ContentService 
{
    private ContentRepository $repository;
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;

    public function __construct(
        ContentRepository $repository,
        SecurityManager $security, 
        ValidationService $validator,
        CacheManager $cache
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function create(array $data)
    {
        return $this->executeSecure(function() use ($data) {
            $validated = $this->validator->validate($data);
            return $this->repository->create($validated);
        });
    }

    public function update(int $id, array $data)
    {
        return $this->executeSecure(function() use ($id, $data) {
            $validated = $this->validator->validate($data);
            return $this->repository->update($id, $validated);
        });
    }

    public function delete(int $id): bool
    {
        return $this->executeSecure(function() use ($id) {
            return $this->repository->delete($id);
        });
    }

    public function publish(int $id)
    {
        return $this->executeSecure(function() use ($id) {
            return $this->repository->publish($id);
        });
    }

    public function getPublished(array $filters = [])
    {
        return $this->executeSecure(function() use ($filters) {
            return $this->repository->getPublished($filters);
        });
    }

    private function executeSecure(callable $operation)
    {
        DB::beginTransaction();

        try {
            $context = $this->security->validateOperation();
            $result = $operation();
            $this->security->validateResult($result);
            
            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e; 
        }
    }

    public function clearCache(): void
    {
        $this->cache->clear('content');
    }
}

class CacheManager
{
    private string $prefix;
    private int $ttl;

    public function __construct(string $prefix = 'cms', int $ttl = 3600)
    {
        $this->prefix = $prefix;
        $this->ttl = $ttl;
    }

    public function remember(string $key, callable $callback)
    {
        return Cache::tags($this->prefix)->remember(
            $this->getKey($key),
            $this->ttl,
            $callback
        );
    }

    public function clear(string $tag = null): void
    {
        $tags = $tag ? [$this->prefix, $tag] : [$this->prefix];
        Cache::tags($tags)->flush();
    }

    private function getKey(string $key): string
    {
        return sprintf('%s:%s', $this->prefix, $key);
    }
}

interface ValidationService
{
    public function validate(array $data): array;
    public function validateRequest($request, array $rules): void;
    public function validateResult($result): bool;
}

interface SecurityManager 
{
    public function validateOperation(): SecurityContext;
    public function validateResult($result): void;
}
