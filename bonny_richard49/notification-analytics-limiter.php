<?php

namespace App\Core\Notification\Analytics\Limiter;

class RateLimiter
{
    private array $limits = [];
    private array $counters = [];
    private array $metrics = [];

    public function addLimit(string $key, int $limit, int $interval): void
    {
        $this->limits[$key] = [
            'limit' => $limit,
            'interval' => $interval,
            'window_start' => time()
        ];
        $this->counters[$key] = 0;
    }

    public function check(string $key, int $increment = 1): bool
    {
        if (!isset($this->limits[$key])) {
            return true;
        }

        $this->updateWindow($key);
        
        if (($this->counters[$key] + $increment) > $this->limits[$key]['limit']) {
            $this->recordMetric($key, 'exceeded');
            return false;
        }

        $this->counters[$key] += $increment;
        $this->recordMetric($key, 'allowed');
        return true;
    }

    public function reset(string $key): void
    {
        if (isset($this->limits[$key])) {
            $this->counters[$key] = 0;
            $this->limits[$key]['window_start'] = time();
            $this->recordMetric($key, 'reset');
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function updateWindow(string $key): void
    {
        $now = time();
        $limit = $this->limits[$key];
        $elapsed = $now - $limit['window_start'];

        if ($elapsed >= $limit['interval']) {
            $this->counters[$key] = 0;
            $this->limits[$key]['window_start'] = $now;
        }
    }

    private function recordMetric(string $key, string $action): void
    {
        if (!isset($this->metrics[$key])) {
            $this->metrics[$key] = [
                'allowed' => 0,
                'exceeded' => 0,
                'reset' => 0
            ];
        }
        $this->metrics[$key][$action]++;
    }
}

class TokenBucketLimiter
{
    private array $buckets = [];
    private array $metrics = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default_capacity' => 60,
            'default_refill_rate' => 1,
            'default_refill_interval' => 1
        ], $config);
    }

    public function createBucket(string $key, int $capacity = null, float $refillRate = null): void
    {
        $capacity = $capacity ?? $this->config['default_capacity'];
        $refillRate = $refillRate ?? $this->config['default_refill_rate'];

        $this->buckets[$key] = [
            'capacity' => $capacity,
            'tokens' => $capacity,
            'refill_rate' => $refillRate,
            'last_refill' => microtime(true),
            'total_tokens' => 0
        ];
    }

    public function consume(string $key, int $tokens = 1): bool
    {
        if (!isset($this->buckets[$key])) {
            $this->createBucket($key);
        }

        $this->refill($key);
        $bucket = &$this->buckets[$key];

        if ($bucket['tokens'] >= $tokens) {
            $bucket['tokens'] -= $tokens;
            $bucket['total_tokens'] += $tokens;
            $this->recordMetric($key, 'consumed', $tokens);
            return true;
        }

        $this->recordMetric($key, 'rejected', $tokens);
        return false;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function refill(string $key): void
    {
        $now = microtime(true);
        $bucket = &$this->buckets[$key];
        $elapsed = $now - $bucket['last_refill'];
        $newTokens = $elapsed * $bucket['refill_rate'];

        if ($newTokens >= 1) {
            $bucket['tokens'] = min(
                $bucket['capacity'],
                $bucket['tokens'] + floor($newTokens)
            );
            $bucket['last_refill'] = $now;
            $this->recordMetric($key, 'refilled', floor($newTokens));
        }
    }

    private function recordMetric(string $key, string $action, int $tokens): void
    {
        if (!isset($this->metrics[$key])) {
            $this->metrics[$key] = [
                'consumed' => 0,
                'rejected' => 0,
                'refilled' => 0,
                'total_tokens' => 0
            ];
        }
        $this->metrics[$key][$action] += $tokens;
        if ($action === 'consumed') {
            $this->metrics[$key]['total_tokens'] += $tokens;
        }
    }
}

class SlidingWindowLimiter
{
    private array $windows = [];
    private array $metrics = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'window_size' => 60,
            'precision' => 1
        ], $config);
    }

    public function isAllowed(string $key, int $limit): bool
    {
        $this->cleanup($key);
        $count = $this->getCurrentCount($key);

        if ($count >= $limit) {
            $this->recordMetric($key, 'rejected');
            return false;
        }

        $this->recordRequest($key);
        $this->recordMetric($key, 'allowed');
        return true;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordRequest(string $key): void
    {
        if (!isset($this->windows[$key])) {
            $this->windows[$key] = [];
        }

        $timestamp = $this->getCurrentTimestamp();
        if (!isset($this->windows[$key][$timestamp])) {
            $this->windows[$key][$timestamp] = 0;
        }

        $this->windows[$key][$timestamp]++;
    }

    private function getCurrentCount(string $key): int
    {
        if (!isset($this->windows[$key])) {
            return 0;
        }

        $count = 0;
        $minTimestamp = $this->getCurrentTimestamp() - $this->config['window_size'];

        foreach ($this->windows[$key] as $timestamp => $requests) {
            if ($timestamp >= $minTimestamp) {
                $count += $requests;
            }
        }

        return $count;
    }

    private function cleanup(string $key): void
    {
        if (!isset($this->windows[$key])) {
            return;
        }

        $minTimestamp = $this->getCurrentTimestamp() - $this->config['window_size'];
        foreach ($this->windows[$key] as $timestamp => $count) {
            if ($timestamp < $minTimestamp) {
                unset($this->windows[$key][$timestamp]);
            }
        }
    }

    private function getCurrentTimestamp(): int
    {
        return floor(microtime(true) / $this->config['precision']) * $this->config['precision'];
    }

    private function recordMetric(string $key, string $action): void
    {
        if (!isset($this->metrics[$key])) {
            $this->metrics[$key] = [
                'allowed' => 0,
                'rejected' => 0
            ];
        }
        $this->metrics[$key][$action]++;
    }
}
