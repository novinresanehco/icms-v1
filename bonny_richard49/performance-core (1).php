<?php
namespace App\Core\Performance;

class CacheManager implements CacheInterface
{
    private CacheStore $store;
    private ValidationService $validator;
    private SecurityManager $security;

    public function remember(string $key, callable $callback, int $ttl = 3600)
    {
        if ($value = $this->get($key)) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function get(string $key)
    {
        $value = $this->store->get($key);
        
        if ($value && !$this->validator->validateCacheData($value)) {
            $this->store->forget($key);
            return null;
        }
        
        return $value;
    }

    public function set(string $key, $value, int $ttl = 3600): void
    {
        $this->security->validateCacheOperation(function() use ($key, $value, $ttl) {
            $this->store->put($key, $value, $ttl);
        });
    }

    public function invalidate(array $keys): void
    {
        foreach ($keys as $key) {
            $this->store->forget($key);
        }
    }
}

class QueueManager implements QueueInterface
{
    private QueueConnection $queue;
    private Monitor $monitor;
    private SecurityManager $security;

    public function push(Job $job): void
    {
        $this