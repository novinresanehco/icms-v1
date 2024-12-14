// app/Core/Cache/CacheManager.php
<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CacheManager 
{
    private array $tags = [];
    private int $defaultTtl;
    
    public function __construct(array $config = [])
    {
        $this->defaultTtl = $config['ttl'] ?? 3600;
    }

    public function tags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        try {
            if (!empty($this->tags)) {
                return Cache::tags($this->tags)->remember($key, $ttl ?? $this->defaultTtl, $callback);
            }
            return Cache::remember($key, $ttl ?? $this->defaultTtl, $callback);
        } catch (\Exception $e) {
            Log::error("Cache error for key {$key}: " . $e->getMessage());
            return $callback();
        } finally {
            $this->tags = [];
        }
    }

    public function forget(string $key): bool
    {
        try {
            if (!empty($this->tags)) {
                return Cache::tags($this->tags)->forget($key);
            }
            return Cache::forget($key);
        } catch (\Exception $e) {
            Log::error("Cache forget error for key {$key}: " . $e->getMessage());
            return false;
        } finally {
            $this->tags = [];
        }
    }

    public function flush(array $tags = []): bool
    {
        try {
            if (!empty($tags)) {
                return Cache::tags($tags)->flush();
            }
            return Cache::flush();
        } catch (\Exception $e) {
            Log::error("Cache flush error: " . $e->getMessage());
            return false;
        }
    }
}

// app/Core/Cache/CacheKeyGenerator.php
<?php

namespace App\Core\Cache;

class CacheKeyGenerator
{
    public static function generate(string $prefix, array $params): string
    {
        $normalizedParams = array_map(function($param) {
            return is_array($param) ? implode('_', $param) : strval($param);
        }, $params);
        
        return $prefix . '_' . md5(implode('_', $normalizedParams));
    }
}

// app/Core/Cache/CachePrefix.php
<?php

namespace App\Core\Cache;

class CachePrefix
{
    const WIDGET = 'widget';
    const MENU = 'menu';
    const CONTENT = 'content';
    const USER = 'user';
    const SETTINGS = 'settings';
}

// app/Core/Cache/Contracts/Cacheable.php
<?php

namespace App\Core\Cache\Contracts;

interface Cacheable
{
    public function getCacheKey(): string;
    public function getCacheTags(): array;
    public function getCacheTtl(): ?int;
}