<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\CacheInterface;
use App\Core\Exceptions\CacheException;

class CacheManager implements CacheInterface 
{
    private array $config;
    private string $prefix;
    private array $tagMap = [];
    private array $locks = [];
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->prefix = $config['prefix'] ?? 'cms:';
        $this->initializeTagMap();
    }

    public function remember(string $key, $value, ?int $ttl = null): mixed
    {
        try {
            $key = $this->prefixKey($key);
            $ttl = $ttl ?? $this->config['default_ttl'] ?? 3600;

            return Cache::remember($key, $ttl, function() use ($value) {
                return is_callable($value) ? $value() : $value;
            });
        } catch (\Exception $e) {
            $this->handleError($e, __METHOD__, compact('key', 'ttl'));
            return is_callable($value) ? $value() : $value;
        }
    }

    public function rememberForever(string $key, $value): mixed
    {
        try {
            $key = $this->prefixKey($key);
            return Cache::rememberForever($key, function() use ($value) {
                return is_callable($value) ? $value() : $value;
            });
        } catch (\Exception $e) {
            $this->handleError($e, __METHOD__, compact('key'));
            return is_callable($value) ? $value() : $value;
        }
    }

    public function tags(array $tags): self
    {
        try {
            foreach ($tags as $tag) {
                $this->validateTag($tag);
                $this->tagMap[$tag] = true;
            }
            return $this;
        } catch (\Exception $e) {
            $this->handleError($e, __METHOD__, compact('tags'));
            return $this;
        }
    }

    public function invalidateTag(string $tag): void
    {
        try {
            $this->validateTag($tag);
            $keys = $this->getKeysByTag($tag);
            
            foreach ($keys as $key) {
                $this->forget($key);
            }
            
            unset($this->tagMap[$tag]);
        } catch (\Exception $e) {
            $this->handleError($e, __METHOD__, compact('tag'));
        }
    }

    public function invalidateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->invalidateTag($tag);
        }
    }

    public function forget(string $key): bool
    {
        try {
            $key = $this->prefixKey($key);
            return Cache::forget($key);
        } catch (\Exception $e) {
            $this->handleError($e, __METHOD__, compact('key'));
            return false;
        }
    }

    public function flush(): bool
    {
        try {
            return Cache::flush();
        } catch (\Exception $e) {
            $this->handleError($e, __METHOD__);
            return false;
        }
    }

    public function getMultiple(array $keys, mixed $default = null): array
    {
        try {
            $prefixedKeys = array_map([$this, 'prefixKey'], $keys);
            $values = Cache::many($prefixedKeys);
            
            $result = [];
            foreach ($keys as $index => $key) {
                $prefixedKey = $prefixedKeys[$index];
                $result[$key] = $values[$prefixedKey] ?? $default;
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->handleError($e, __METHOD__, compact('keys'));
            return array_fill_keys($keys, $default);
        }
    }

    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        try {
            $ttl = $ttl ?? $this->config['default_ttl'] ?? 3600;
            $prefixedValues = [];
            
            foreach ($values as $key => $value) {
                $prefixedValues[$this->prefixKey($key)] = $value;
            }
            
            return Cache::putMany($prefixedValues, $ttl);
        } catch (\Exception $e) {
            $this->handleError($e, __METHOD__, compact('values', 'ttl'));
            return false;
        }
    }

    public function lock(string $key, int $seconds = 0): bool
    {
        try {
            $lock = Cache::lock($this->prefixKey($key) . ':lock', $seconds);
            if ($lock->get()) {
                $this->locks[$key] = $lock;
                return true;
            }
            return false;
        } catch (\Exception $e) {
            $this->handleError($e, __METHOD__, compact('key', 'seconds'));
            return false;
        }
    }

    public function unlock(string $key): bool
    {
        try {
            if (isset($this->locks[$key])) {
                $this->locks[$key]->release();
                unset($this->locks[$key]);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            $this->handleError($e, __METHOD__, compact('key'));
            return false;
        }
    }

    protected function prefixKey(string $key): string
    {
        return $this->prefix . $key;
    }

    protected function validateTag(string $tag): void
    {
        if (!preg_match('/^[a-zA-Z0-9_\-:]+$/', $tag)) {
            throw new CacheException("Invalid tag format: {$tag}");
        }
    }

    protected function initializeTagMap(): void
    {
        try {
            $this->tagMap = Cache::get($this->prefix . 'tag_map', []);
        } catch (\Exception $e) {
            $this->handleError($e, __METHOD__);
            $this->tagMap = [];
        }
    }

    protected function getKeysByTag(string $tag): array
    {
        try {
            return Cache::get($this->prefix . 'tag:' . $tag, []);
        } catch (\Exception $e) {
            $this->handleError($e, __METHOD__, compact('tag'));
            return [];
        }
    }

    protected function handleError(\Exception $e, string $method, array $context = []): void
    {
        $message = "Cache operation failed in {$method}: " . $e->getMessage();
        throw new CacheException($message, 0, $e);
    }

    public function __destruct()
    {
        foreach ($this->locks as $lock) {
            try {
                $lock->release();
            } catch (\Exception $e) {
                // Silently handle release failures in destructor
            }
        }
    }
}
