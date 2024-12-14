<?php

namespace App\Core\Cache;

class CriticalCacheService
{
    private $store;
    private $monitor;

    public const DEFAULT_TTL = 3600;

    public function get(string $key): ?array
    {
        try {
            return $this->store->get($key);
        } catch (\Exception $e) {
            $this->monitor->logCacheFailure('read', $e);
            return null;
        }
    }

    public function set(string $key, array $data): void
    {
        try {
            $this->store->set($key, $data, self::DEFAULT_TTL);
        } catch (\Exception $e) {
            $this->monitor->logCacheFailure('write', $e);
        }
    }

    public function clear(string $key): void
    {
        try {
            $this->store->delete($key);
        } catch (\Exception $e) {
            $this->monitor->logCacheFailure('delete', $e);
        }
    }
}
