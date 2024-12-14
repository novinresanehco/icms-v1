namespace App\Core\Cache;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{Cache, Log};
use App\Exceptions\CacheException;

class CacheManagementService implements CacheManagementInterface
{
    private SecurityManager $security;
    private CacheValidator $validator;
    private AuditLogger $logger;
    private CacheConfig $config;
    private array $stores = [];

    private const CACHE_LEVELS = [
        'memory' => 'array',
        'local' => 'file',
        'distributed' => 'redis'
    ];

    public function __construct(
        SecurityManager $security,
        CacheValidator $validator,
        AuditLogger $logger,
        CacheConfig $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->config = $config;
    }

    public function get(string $key, SecurityContext $context): mixed
    {
        return $this->security->executeCriticalOperation(
            new CacheRetrievalOperation($key),
            $context,
            function() use ($key) {
                foreach (self::CACHE_LEVELS as $level => $driver) {
                    if ($value = $this->getFromStore($level, $key)) {
                        $this->logger->logCacheHit($level, $key);
                        return $this->validator->validateCachedData($value);
                    }
                }
                
                $this->logger->logCacheMiss($key);
                return null;
            }
        );
    }

    public function set(string $key, $value, int $ttl = null, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new CacheStorageOperation($key, $value, $ttl),
            $context,
            function() use ($key, $value, $ttl) {
                $validated = $this->validator->validateDataForCaching($value);
                $ttl = $ttl ?? $this->config->getDefaultTTL();

                foreach (self::CACHE_LEVELS as $level => $driver) {
                    $this->setInStore($level, $key, $validated, $ttl);
                }
                
                $this->logger->logCacheSet($key, $ttl);
            }
        );
    }

    public function remember(string $key, \Closure $callback, int $ttl = null, SecurityContext $context): mixed
    {
        return $this->security->executeCriticalOperation(
            new CacheRememberOperation($key, $ttl),
            $context,
            function() use ($key, $callback, $ttl) {
                if ($value = $this->get($key, $context)) {
                    return $value;
                }

                $value = $callback();
                $this->set($key, $value, $ttl, $context);
                
                return $value;
            }
        );
    }

    public function forget(string $key, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new CacheForgetOperation($key),
            $context,
            function() use ($key) {
                foreach (self::CACHE_LEVELS as $level => $driver) {
                    $this->forgetInStore($level, $key);
                }
                
                $this->logger->logCacheForget($key);
            }
        );
    }

    public function flush(SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new CacheFlushOperation(),
            $context,
            function() {
                foreach (self::CACHE_LEVELS as $level => $driver) {
                    $this->getStore($level)->flush();
                }
                
                $this->logger->logCacheFlush();
            }
        );
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache(
            $this->security,
            $this->validator,
            $this->logger,
            $this->config,
            $tags
        );
    }

    private function getStore(string $level): \Illuminate\Cache\Repository
    {
        if (!isset($this->stores[$level])) {
            $driver = self::CACHE_LEVELS[$level];
            $this->stores[$level] = Cache::store($driver);
        }
        
        return $this->stores[$level];
    }

    private function getFromStore(string $level, string $key): mixed
    {
        try {
            $store = $this->getStore($level);
            return $store->get($this->generateKey($key));
        } catch (\Exception $e) {
            $this->handleCacheError($level, 'get', $e);
            return null;
        }
    }

    private function setInStore(string $level, string $key, $value, int $ttl): void
    {
        try {
            $store = $this->getStore($level);
            $store->put($this->generateKey($key), $value, $ttl);
        } catch (\Exception $e) {
            $this->handleCacheError($level, 'set', $e);
        }
    }

    private function forgetInStore(string $level, string $key): void
    {
        try {
            $store = $this->getStore($level);
            $store->forget($this->generateKey($key));
        } catch (\Exception $e) {
            $this->handleCacheError($level, 'forget', $e);
        }
    }

    private function generateKey(string $key): string
    {
        return hash('sha256', $key . $this->config->getKeySalt());
    }

    private function handleCacheError(string $level, string $operation, \Exception $e): void
    {
        $this->logger->logCacheError($level, $operation, $e);
        
        if ($this->config->isStrictMode()) {
            throw new CacheException(
                "Cache {$operation} failed on {$level} level: " . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function getMultiple(array $keys, SecurityContext $context): array
    {
        return $this->security->executeCriticalOperation(
            new CacheMultipleRetrievalOperation($keys),
            $context,
            function() use ($keys) {
                $results = [];
                
                foreach ($keys as $key) {
                    $results[$key] = $this->get($key, $context);
                }
                
                return $results;
            }
        );
    }

    public function setMultiple(array $values, int $ttl = null, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new CacheMultipleStorageOperation($values, $ttl),
            $context,
            function() use ($values, $ttl) {
                foreach ($values as $key => $value) {
                    $this->set($key, $value, $ttl, $context);
                }
            }
        );
    }
}
