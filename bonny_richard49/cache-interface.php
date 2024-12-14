<?php

namespace App\Core\Interfaces;

interface CacheServiceInterface
{
    public function get(string $key, $default = null);
    public function put(string $key, $value, ?int $ttl = null): bool;
    public function remember(string $key, callable $callback, ?int $ttl = null);
    public function rememberForever(string $key, callable $callback);
    public function forget(string $key): bool;
    public function flush(): bool;
    public function tags(array $tags): self;
    public function lock(string $key, int $timeout = 10);
}
