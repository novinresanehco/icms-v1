<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, Log, Event};
use App\Core\Interfaces\{CacheManagerInterface, ValidationInterface};
use App\Core\Events\{CacheEvent, SystemEvent};
use App\Core\Exceptions\{CacheException, ValidationException};

class CacheManager implements CacheManagerInterface
{
    protected ValidationInterface $validator;
    protected array $config;
    protected array $tags = [];
    protected array $metrics = [];

    protected const CRITICAL_KEYS = [
        'security',
        'auth',
        'config',
        'system'
    ];

    public function __construct(
        ValidationInterface $validator,
        array $config
    ) {
        $this->validator = $validator;
        $this->config = $config;
        $this->initializeMetrics();
    }

    public function remember(string $key, $data, int $ttl = null): mixed
    {
        $this->validateKey($key);
        $ttl = $ttl ?? $this->config['default_ttl'];

        try {
            $result = Cache::tags($this->getTags($key))
                ->remember($key, $ttl, function() use ($data) {
                    return is_callable($data) ? $data() : $data;
                });

            $this->trackMetric('hits', $key);
            return $result;

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'remember', $key);
            return is_callable($data) ? $data() : $data;
        }
    }

    public function store(string $key, $data, int $ttl = null): bool
    {
        $this->validateKey($key);
        $ttl = $ttl ?? $this->config['default_ttl'];

        try {
            $stored = Cache::tags($this->getTags($key))
                ->put($key, $data, $ttl);

            if ($stored) {
                $this->trackMetric('stores', $key);
            }

            return $stored;

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'store', $key);
            return false;
        }
    }

    public function get(string $key, $default = null): mixed
    {
        $this->validateKey($key);

        try {
            if ($value = Cache::tags($this->getTags($key))->get($key)) {
                $this->trackMetric('hits', $key);
                return $value;
            }

            $this->trackMetric('misses', $key);
            return $default;

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'get', $key);
            return $default;
        }
    }

    public function invalidate($keys, bool $isPattern = false): void
    {
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        foreach ($keys as $key) {
            try {
                if ($isPattern) {
                    Cache::tags($this->getTags($key))->flush();
                } else {
                    Cache::tags($this->getTags($key))->forget($key);
                }

                $this->trackMetric('invalidations', $key);
                Event::dispatch(new CacheEvent('invalidated', ['key' => $key]));

            } catch (\Exception $e) {
                $this->handleCacheFailure($e, 'invalidate', $key);
            }
        }
    }

    public function invalidateTag(string $tag): void
    {
        try {
            Cache::tags($tag)->flush();
            $this->trackMetric('tag_invalidations', $tag);
            Event::dispatch(new CacheEvent('tag_invalidated', ['tag' => $tag]));

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'invalidate_tag', $tag);
        }
    }

    public function clear(): void
    {
        try {
            Cache::flush();
            $this->resetMetrics();
            Event::dispatch(new CacheEvent('cleared'));

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'clear');
        }
    }

    public function addTags(string $pattern, array $tags): void
    {
        $this->tags[$pattern] = array_unique(
            array_merge($this->tags[$pattern] ?? [], $tags)
        );
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    protected function validateKey(string $key): void
    {
        if (!$this->validator->validate(['key' => $key], [
            'key' => 'required|string|max:255|regex:/^[\w\-\.]+$/'
        ])) {
            throw new ValidationException('Invalid cache key format');
        }

        if ($this->isCriticalKey($key) && !$this->security->canAccessCriticalCache()) {
            throw new SecurityException('Unauthorized access to critical cache');
        }
    }

    protected function getTags(string $key): array
    {
        $tags = ['system'];

        foreach ($this->tags as $pattern => $patternTags) {
            if (preg_match($pattern, $key)) {
                $tags = array_merge($tags, $patternTags);
            }
        }

        return array_unique($tags);
    }

    protected function isCriticalKey(string $key): bool
    {
        foreach (self::CRITICAL_KEYS as $criticalKey) {
            if (strpos($key, $criticalKey) === 0) {
                return true;
            }
        }
        return false;
    }

    protected function trackMetric(string $type, string $key): void
    {
        $this->metrics[$type]++;
        $this->metrics['keys'][$key][$type] = ($this->metrics['keys'][$key][$type] ?? 0) + 1;
        $this->metrics['last_access'] = now();

        if ($this->shouldReportMetrics()) {
            $this->reportMetrics();
        }
    }

    protected function initializeMetrics(): void
    {
        $this->metrics = [
            'hits' => 0,
            'misses' => 0,
            'stores' => 0,
            'invalidations' => 0,
            'tag_invalidations' => 0,
            'failures' => 0,
            'keys' => [],
            'last_access' => null,
            'last_report' => now()
        ];
    }

    protected function resetMetrics(): void
    {
        $this->initializeMetrics();
    }

    protected function shouldReportMetrics(): bool
    {
        return $this->metrics['last_report']->diffInMinutes(now()) >= 
            $this->config['metrics_report_interval'];
    }

    protected function reportMetrics(): void
    {
        Event::dispatch(new SystemEvent('cache_metrics', [
            'metrics' => $this->metrics
        ]));

        $this->metrics['last_report'] = now();
    }

    protected function handleCacheFailure(\Exception $e, string $operation, string $key = null): void
    {
        $this->metrics['failures']++;

        $context = [
            'operation' => $operation,
            'key' => $key,
            'exception' => [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        ];

        Log::error('Cache operation failed', $context);
        Event::dispatch(new CacheEvent('failure', $context));

        if ($this->isSystemCritical($operation, $key)) {
            Event::dispatch(new SystemEvent('critical_cache_failure', $context));
            throw new CacheException(
                "Critical cache operation failed: {$operation}",
                0,
                $e
            );
        }
    }

    protected function isSystemCritical(string $operation, ?string $key): bool
    {
        if ($key && $this->isCriticalKey($key)) {
            return true;
        }

        return in_array($operation, ['clear', 'invalidate_tag']);
    }
}
