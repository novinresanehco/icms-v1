<?php

namespace App\Core\Cache;

class CacheManager
{
    private $store;
    private $ttl = 3600; // یک ساعت 

    public function get(string $key)
    {
        return $this->store->get($key);
    }

    public function set(string $key, $data): void
    {
        $this->store->set($key, $data, $this->ttl);
    }

    public function clear(string $key): void
    {
        $this->store->delete($key);
    }
}
