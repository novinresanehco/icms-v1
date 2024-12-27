<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class MetricsCollector implements MetricsInterface
{
    private SecurityManager $security;
    private string $prefix;
    private array $buffer = [];
    private int $batchSize;

    public function __construct(
        SecurityManager $security,
        string $prefix = 'metrics:',
        int $batchSize = 100
    ) {
        $this->security = $security;
        $this->prefix = $prefix;
        $this->batchSize = $batchSize;
    }

    public function increment(string $metric, array $tags = []): void
    {
        $key = $this->formatMetricKey($metric, $tags);
        Redis::incr($key);
        
        $this->buffer[] = [
            'type' => 'increment',
            'metric' => $metric,
            'tags' => $tags,
            'timestamp' => microtime(true)
        ];
        
        $this->flushIfNeeded();
    }

    public function record(string $metric, $value, array $tags = []): void
    {
        $key = $this->formatMetricKey($metric, $tags);
        Redis::rpush($key, $value);
        
        $this->buffer[] = [
            'type' => 'record',
            'metric' => $metric,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true)
        ];
        
        $this->flushIfNeeded();
    }

    public function timing(string $metric, float $time, array $tags = []): void
    {
        $this->record($metric, $time, array_merge($tags, ['type' => 'timing']));
    }

    public function gauge(string $metric, $value, array $tags = []): void
    {
        $key = $this->formatMetricKey($metric, $tags);
        Redis::set($key, $value);
        
        $this->buffer[] = [
            'type' => 'gauge',
            'metric' => $metric,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true)
        ];
        
        $this->flushIfNeeded();
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        try {
            $this->persistMetrics($this->buffer);
            $this->buffer = [];
        } catch (\Exception $e) {
            Log::error('Failed to flush metrics', [
                'error' => $e->getMessage(),
                'metrics_count' => count($this->buffer)
            ]);
        }
    }

    protected function formatMetricKey(string $metric, array $tags): string
    {
        $tagString = empty($tags) ? '' : ':' . $this->formatTags($tags);
        return $this->prefix . $metric . $tagString;
    }

    protected function formatTags(array $tags): string
    {
        ksort($tags);
        
        return implode(':', array_map(
            fn($k, $v) => $k . '=' . $v,
            array_keys($tags),
            $tags
        ));
    }

    protected function flushIfNeeded(): void
    {
        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    protected function persistMetrics(array $metrics): void
    {
        $encrypted = $this->security->encrypt(json_encode($metrics));
        
        DB::transaction(function () use ($encrypted) {
            DB::table('metrics')->insert([
                'data' => $encrypted,
                'created_at' => now()
            ]);
        });
    }
}

interface MetricsInterface
{
    public function increment(string $metric, array $tags = []): void;
    public function record(string $metric, $value, array $tags = []): void;
    public function timing(string $metric, float $time, array $tags = []): void;
    public function gauge(string $metric, $value, array $tags = []): void;
    public function flush(): void;
}
