namespace App\Core\Template\Cache;

class TemplateCacheManager implements CacheManagerInterface 
{
    private CacheStore $store;
    private SecurityManager $security;
    private array $compiledTemplates = [];
    private const CACHE_PREFIX = 'template_';
    private const CACHE_TTL = 3600;

    public function remember(string $key, $value): mixed 
    {
        $cacheKey = $this->generateCacheKey($key);
        
        if ($cached = $this->getFromMultiLevelCache($cacheKey)) {
            return $cached;
        }

        $value = value($value);
        $this->storeInMultiLevelCache($cacheKey, $value);
        
        return $value;
    }

    private function getFromMultiLevelCache(string $key): mixed 
    {
        // L1: Memory cache
        if (isset($this->compiledTemplates[$key])) {
            return $this->compiledTemplates[$key];
        }

        // L2: Redis/Memcached
        if ($cached = $this->store->get($key)) {
            $this->compiledTemplates[$key] = $cached;
            return $cached;
        }

        return null;
    }

    private function storeInMultiLevelCache(string $key, $value): void 
    {
        // Validate before caching
        if (!$this->security->validateCacheableContent($value)) {
            throw new SecurityException('Invalid content for caching');
        }

        // L1: Memory cache
        $this->compiledTemplates[$key] = $value;

        // L2: Redis/Memcached with compression
        $this->store->put(
            $key, 
            $value, 
            self::CACHE_TTL,
            ['compressed' => true]
        );
    }

    private function generateCacheKey(string $key): string 
    {
        return self::CACHE_PREFIX . hash('xxh3', $key);
    }

    public function clear(string $template = null): void 
    {
        if ($template) {
            $key = $this->generateCacheKey($template);
            unset($this->compiledTemplates[$key]);
            $this->store->forget($key);
        } else {
            $this->compiledTemplates = [];
            $this->store->flush();
        }
    }

    public function warmUp(array $templates): void 
    {
        foreach ($templates as $template) {
            $this->remember($template, fn() => 
                $this->compileTemplate($template)
            );
        }
    }

    private function compileTemplate(string $template): string 
    {
        return $this->security->validateTemplate($template)
            ? $this->compiler->compile($template)
            : throw new SecurityException('Template failed security validation');
    }
}

class PreloadManager 
{
    private TemplateCacheManager $cache;
    private array $criticalTemplates;

    public function preloadCriticalTemplates(): void 
    {
        $this->cache->warmUp($this->criticalTemplates);
    }
}

interface CacheManagerInterface 
{
    public function remember(string $key, $value): mixed;
    public function clear(string $template = null): void;
    public function warmUp(array $templates): void;
}
