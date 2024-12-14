<?php

namespace App\Core\RateLimiter;

class RateLimiter
{
    private LimitStore $store;
    private array $limiters = [];

    public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        if ($this->tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        $this->hit($key, $decaySeconds);
        return true;
    }

    public function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        if ($this->attempts($key) >= $maxAttempts) {
            if ($this->store->has($key . ':timer')) {
                return true;
            }
            $this->resetAttempts($key);
        }
        return false;
    }

    public function hit(string $key, int $decaySeconds = 60): int
    {
        $this->store->add($key . ':timer', time() + $decaySeconds, $decaySeconds);
        $hits = $this->store->increment($key);
        $this->store->add($key, $hits, $decaySeconds);

        return $hits;
    }

    public function attempts(string $key): int
    {
        return $this->store->get($key, 0);
    }

    public function resetAttempts(string $key): bool
    {
        return $this->store->forget($key);
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        return max(0, $maxAttempts - $this->attempts($key));
    }

    public function retriesLeft(string $key, int $maxAttempts): int
    {
        return $this->remaining($key, $maxAttempts);
    }

    public function availableIn(string $key): int
    {
        return max(0, $this->store->get($key . ':timer', 0) - time());
    }

    public function clear(string $key): void
    {
        $this->store->forget($key);
        $this->store->forget($key . ':timer');
    }
}

class LimitStore
{
    private $connection;
    private string $prefix;

    public function get(string $key, $default = null)
    {
        return $this->connection->get($this->prefix . $key) ?? $default;
    }

    public function add(string $key, $value, int $ttl): bool
    {
        return $this->connection->set(
            $this->prefix . $key,
            $value,
            ['nx', 'ex' => $ttl]
        );
    }

    public function increment(string $key): int
    {
        return $this->connection->incr($this->prefix . $key);
    }

    public function forget(string $key): bool
    {
        return (bool) $this->connection->del($this->prefix . $key);
    }

    public function has(string $key): bool
    {
        return (bool) $this->connection->exists($this->prefix . $key);
    }
}

class WindowLimiter
{
    private LimitStore $store;
    private int $windowSeconds;
    private int $maxRequests;

    public function __construct(LimitStore $store, int $windowSeconds, int $maxRequests)
    {
        $this->store = $store;
        $this->windowSeconds = $windowSeconds;
        $this->maxRequests = $maxRequests;
    }

    public function attempt(string $key): bool
    {
        $currentTime = time();
        $windowStart = $currentTime - $this->windowSeconds;
        
        $this->clearOldAttempts($key, $windowStart);
        
        $attempts = $this->getAttempts($key);
        if (count($attempts) >= $this->maxRequests) {
            return false;
        }

        $this->recordAttempt($key, $currentTime);
        return true;
    }

    private function clearOldAttempts(string $key, int $windowStart): void
    {
        $attempts = $this->getAttempts($key);
        $validAttempts = array_filter(
            $attempts,
            fn($timestamp) => $timestamp >= $windowStart
        );

        $this->store->add($key, json_encode($validAttempts), $this->windowSeconds);
    }

    private function recordAttempt(string $key, int $timestamp): void
    {
        $attempts = $this->getAttempts($key);
        $attempts[] = $timestamp;
        $this->store->add($key, json_encode($attempts), $this->windowSeconds);
    }

    private function getAttempts(string $key): array
    {
        return json_decode($this->store->get($key, '[]'), true);
    }
}

class TokenBucketLimiter
{
    private LimitStore $store;
    private int $capacity;
    private float $refillRate;

    public function __construct(LimitStore $store, int $capacity, float $refillRate)
    {
        $this->store = $store;
        $this->capacity = $capacity;
        $this->refillRate = $refillRate;
    }

    public function attempt(string $key): bool
    {
        $bucket = $this->getBucket($key);
        $now = microtime(true);

        $timePassed = $now - $bucket['lastRefill'];
        $tokensToAdd = $timePassed * $this->refillRate;
        
        $bucket['tokens'] = min(
            $this->capacity,
            $bucket['tokens'] + $tokensToAdd
        );

        if ($bucket['tokens'] < 1) {
            $this->storeBucket($key, $bucket);
            return false;
        }

        $bucket['tokens']--;
        $bucket['lastRefill'] = $now;
        $this->storeBucket($key, $bucket);

        return true;
    }

    private function getBucket(string $key): array
    {
        $bucket = json_decode($this->store->get($key), true);
        
        if (!$bucket) {
            $bucket = [
                'tokens' => $this->capacity,
                'lastRefill' => microtime(true)
            ];
        }

        return $bucket;
    }

    private function storeBucket(string $key, array $bucket): void
    {
        $this->store->add($key, json_encode($bucket), 3600);
    }
}

interface RateLimiterStrategy
{
    public function attempt(string $key): bool;
    public function reset(string $key): void;
}

class RateLimiterException extends \Exception {}
