<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, Log, DB};
use Predis\Client as Redis;

class CacheSystem
{
    private Redis $redis;
    private array $config;

    public function remember(string $key, $value, int $ttl = 3600)
    {
        return Cache::remember($key, $ttl, $value);
    }

    public function invalidate(string $key): void
    {
        Cache::forget($key);
    }
    
    public function invalidatePattern(string $pattern): void
    {
        $this->redis->eval(
            "return redis.call('del', unpack(redis.call('keys', ARGV[1])))",
            0,
            $pattern
        );
    }
}

class DatabaseManager
{
    public function transaction(callable $callback)
    {
        return DB::transaction($callback);
    }
    
    public function query(): \Illuminate\Database\Query\Builder
    {
        return DB::table($this->table);
    }
}

class LogManager
{
    public function critical(string $message, array $context = []): void
    {
        Log::critical($message, $this->enrichContext($context));
    }

    public function error(string $message, array $context = []): void
    {
        Log::error($message, $this->enrichContext($context));
    }

    private function enrichContext(array $context): array
    {
        return array_merge($context, [
            'timestamp' => microtime(true),
            'memory' => memory_get_usage(true),
            'request_id' => request()->id()
        ]);
    }
}

class HealthMonitor
{
    private array $metrics = [];
    
    public function recordMetric(string $name, $value): void
    {
        $this->metrics[$name] = [
            'value' => $value,
            'timestamp' => microtime(true)
        ];
    }

    public function check(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage()
        ];
    }

    private function checkDatabase(): bool
    {
        try {
            DB::select('SELECT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkCache(): bool
    {
        try {
            Cache::set('health_check', true, 1);
            return Cache::get('health_check') === true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function checkStorage(): bool
    {
        return is_writable(storage_path());
    }
}
