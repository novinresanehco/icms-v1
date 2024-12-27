<?php

namespace App\Core\Template\Caching;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Template\Exceptions\{CacheException, SecurityException};

class TemplateCacheManager
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private string $cachePath;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        string $cachePath,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->cachePath = $cachePath;
        $this->config = $config;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->generateCacheKey($key);
        
        if ($this->has($cacheKey)) {
            return $this->get($cacheKey);
        }

        $value = $callback();
        $this->put($cacheKey, $value, $ttl);
        
        return $value;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->validateCacheOperation($key);
        
        $this->security->executeInContext(function() use ($key, $value, $ttl) {
            $path = $this->getCachePath($key);
            
            if (!$this->security->validatePath($path)) {
                throw new SecurityException("Invalid cache path: {$path}");
            }

            $serialized = serialize($value);
            $encrypted = $this->security->encrypt($serialized);
            
            $metadata = [
                'key' => $key,
                'ttl' => $ttl,
                'created' => time(),
                'hash' => hash('sha256', $encrypted)
            ];

            file_put_contents($path . '.meta', serialize($metadata));
            file_put_contents($path . '.cache', $encrypted);
            
            $this->cache->put($key, $metadata, $ttl);
        });
    }

    public function get(string $key): mixed
    {
        $this->validateCacheOperation($key);

        return $this->security->executeInContext(function() use ($key) {
            $path = $this->getCachePath($key);
            
            if (!file_exists($path . '.cache')) {
                throw new CacheException("Cache not found: {$key}");
            }

            $metadata = unserialize(file_get_contents($path . '.meta'));
            
            if ($this->isExpired($metadata)) {
                $this->forget($key);
                throw new CacheException("Cache expired: {$key}");
            }

            $encrypted = file_get_contents($path . '.cache');
            
            if (hash('sha256', $encrypted) !== $metadata['hash']) {
                $this->forget($key);
                throw new SecurityException("Cache integrity check failed: {$key}");
            }

            $decrypted = $this->security->decrypt($encrypted);
            return unserialize($decrypted);
        });
    }

    public function has(string $key): bool
    {
        try {
            $this->validateCacheOperation($key);
            $path = $this->getCachePath($key);
            
            if (!file_exists($path . '.cache')) {
                return false;
            }

            $metadata = unserialize(file_get_contents($path . '.meta'));
            return !$this->isExpired($metadata);

        } catch (\Exception $e) {
            return false;
        }
    }

    public function forget(string $key): void
    {
        $this->validateCacheOperation($key);

        $this->security->executeInContext(function() use ($key) {
            $path = $this->getCachePath($key);
            
            @unlink($path . '.cache');
            @unlink($path . '.meta');
            
            $this->cache->forget($key);
        });
    }

    public function flush(): void
    {
        $this->security->executeInContext(function() {
            $files = glob($this->cachePath . '/*');
            
            foreach ($files as $file) {
                if ($this->security->validateFile($file)) {
                    @unlink($file);
                }
            }
            
            $this->cache->flush();
        });
    }

    private function generateCacheKey(string $key): string
    {
        return hash('sha256', $key . $this->config['salt'] ?? '');
    }

    private function getCachePath(string $key): string
    {
        return $this->cachePath . '/' . $this->generateCacheKey($key);
    }

    private function isExpired(array $metadata): bool
    {
        if (!isset($metadata['ttl'])) {
            return false;
        }

        return time() - $metadata['created'] > $metadata['ttl'];
    }

    private function validateCacheOperation(string $key): void
    {
        if (!$this->security->validateResource($key)) {
            throw new SecurityException("Invalid cache key: {$key}");
        }
    }
}

class CompilationCache
{
    private TemplateCacheManager $cache;
    private array $compilers = [];
    private array $dependencies = [];

    public function __construct(TemplateCacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function remember(string $template, callable $compiler): string
    {
        $key = $this->getCacheKey($template);

        return $this->cache->remember($key, function() use ($template, $compiler) {
            $compiled = $compiler();
            $this->storeDependencies($template);
            return $compiled;
        });
    }

    public function flush(string $template): void
    {
        $key = $this->getCacheKey($template);
        $this->cache->forget($key);
        
        if (isset($this->dependencies[$template])) {
            foreach ($this->dependencies[$template] as $dependency) {
                $this->flush($dependency);
            }
        }
    }

    public function extends(string $child, string $parent): void
    {
        if (!isset($this->dependencies[$parent])) {
            $this->dependencies[$parent] = [];
        }
        
        $this->dependencies[$parent][] = $child;
    }

    private function getCacheKey(string $template): string
    {
        return 'template.compiled.' . hash('sha256', $template);
    }

    private function storeDependencies(string $template): void
    {
        $key = 'template.dependencies.' . hash('sha256', $template);
        $this->cache->put($key, $this->dependencies);
    }
}
