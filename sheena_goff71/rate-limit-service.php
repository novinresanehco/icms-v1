<?php

namespace App\Core\RateLimit\Services;

use App\Core\RateLimit\Models\RateLimit;
use App\Core\RateLimit\Repositories\RateLimitRepository;
use Illuminate\Support\Facades\Cache;

class RateLimitService
{
    public function __construct(
        private RateLimitRepository $repository,
        private RateLimitValidator $validator
    ) {}

    public function attempt(string $key, array $options = []): bool
    {
        $this->validator->validateAttempt($key, $options);

        $limit = $options['limit'] ?? config('rate-limit.default_limit', 60);
        $ttl = $options['ttl'] ?? config('rate-limit.default_ttl', 3600);
        $identifier = $this->getIdentifier($key);

        $attempts = (int) Cache::get($identifier, 0);

        if ($attempts >= $limit) {
            $this->recordExceeded($key, $attempts);
            return false;
        }

        Cache::add($identifier, 0, $ttl);
        Cache::increment($identifier);

        $this->recordAttempt($key);
        return true;
    }

    public function clear(string $key): void
    {
        $identifier = $this->getIdentifier($key);
        Cache::forget($identifier);
    }

    public function getAttempts(string $key): int
    {
        $identifier = $this->getIdentifier($key);
        return (int) Cache::get($identifier, 0);
    }

    public function getRemainingAttempts(string $key, int $limit = null): int
    {
        $limit = $limit ?? config('rate-limit.default_limit', 60);
        return max(0, $limit - $this->getAttempts($key));
    }

    public function isExceeded(string $key, int $limit = null): bool
    {
        return $this->getRemainingAttempts($key, $limit) === 0;
    }

    public function when(string $key, int $limit, callable $callback, callable $otherwise = null): mixed
    {
        if ($this->attempt($key, ['limit' => $limit])) {
            return $callback();
        }

        return $otherwise ? $otherwise() : null;
    }

    public function getHistory(string $key): Collection
    {
        return $this->repository->getHistory($key);
    }

    public function getStats(): array
    {
        return $this->repository->getStats();
    }

    protected function getIdentifier(string $key): string
    {
        return 'rate_limit:' . md5($key);
    }

    protected function recordAttempt(string $key): void
    {
        $this->repository->recordAttempt($key);
    }

    protected function recordExceeded(string $key, int $attempts): void
    {
        $this->repository->recordExceeded($key, $attempts);
    }
}
