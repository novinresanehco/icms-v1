<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;

class CacheManager implements CacheManagerInterface 
{
    private SecurityManager $security;
    private int $defaultTtl;
    
    public function __construct(SecurityManager $security, int $defaultTtl = 3600)
    {
        $this->security = $security;
        $this->defaultTtl = $defaultTtl;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? $this->defaultTtl;
        
        if ($value = Cache::get($key)) {
            return $this->security->decrypt($value);
        }

        $value = $callback();
        $encrypted = $this->security->encrypt($value);
        
        Cache::put($key, $encrypted, $ttl);
        return $value;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        $encrypted = $this->security->encrypt($value);
        Cache::put($key, $encrypted, $ttl ?? $this->defaultTtl);
    }

    public function get(string $key): mixed
    {
        if ($value = Cache::get($key)) {
            return $this->security->decrypt($value);
        }
        return null;
    }

    public function forget(string $key): void
    {
        Cache::forget($key);
    }

    public function tags(array $tags): self
    {
        $instance = clone $this;
        Cache::tags($tags);
        return $instance;
    }

    public function flush(): void
    {
        Cache::flush();
    }
}
