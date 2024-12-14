namespace App\Core\Service;

use App\Core\Repository\RepositoryInterface;
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Monitoring\MonitoringService;
use App\Core\Exceptions\ServiceException;
use Illuminate\Support\Facades\{DB, Log};

abstract class BaseService
{
    protected RepositoryInterface $repository;
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected MonitoringService $monitor;
    
    protected string $cachePrefix = 'service';
    protected int $cacheTime = 60;

    public function __construct(
        RepositoryInterface $repository,
        SecurityManager $security,
        CacheManager $cache,
        MonitoringService $monitor
    ) {
        $this->repository = $repository;
        $this->security = $security;
        $this->cache = $cache;
        $this->monitor = $monitor;
    }

    protected function executeOperation(callable $operation, array $context = []): mixed
    {
        $operationId = $this->monitor->startOperation();
        
        DB::beginTransaction();
        
        try {
            $this->security->validateOperation($context);
            
            $result = $operation();
            
            DB::commit();
            
            $this->monitor->recordSuccess($operationId);
            $this->clearServiceCache();
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->monitor->recordFailure($operationId, $e);
            $this->handleOperationFailure($e, $context);
            
            throw new ServiceException(
                'Operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    protected function getCached(string $key, callable $callback): mixed
    {
        $cacheKey = $this->getCacheKey($key);
        
        return $this->cache->remember(
            $cacheKey,
            $this->cacheTime,
            function() use ($callback, $key) {
                $this->monitor->recordCacheGeneration($key);
                return $callback();
            }
        );
    }

    protected function clearServiceCache(): void
    {
        $this->cache->clearPrefix($this->cachePrefix);
        $this->monitor->recordCacheClear($this->cachePrefix);
    }

    protected function handleOperationFailure(\Exception $e, array $context): void
    {
        Log::error('Service operation failed', [
            'service' => static::class,
            'error' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isSystemFailure($e)) {
            $this->notifySystemAdministrators($e, $context);
        }
    }

    protected function getCacheKey(string $key): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->cachePrefix,
            static::class,
            $key
        );
    }

    protected function isSystemFailure(\Exception $e): bool
    {
        return $e->getCode() >= 500;
    }

    protected function notifySystemAdministrators(\Exception $e, array $context): void
    {
        // Critical system alert implementation
    }

    abstract protected function getValidationRules(): array;
}
