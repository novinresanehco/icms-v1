<?php

namespace App\Core\Repositories;

use App\Core\Models\Statistic;
use App\Core\Exceptions\StatException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{DB, Cache};

class StatisticsRepository extends Repository
{
    protected string $cachePrefix = 'stats:';
    protected int $cacheDuration = 300; // 5 minutes

    public function increment(string $key, int $value = 1, array $metadata = []): void
    {
        DB::transaction(function() use ($key, $value, $metadata) {
            $stat = $this->getOrCreateStat($key);
            
            $stat->increment('count', $value);
            $stat->update(['metadata' => array_merge(
                $stat->metadata ?? [],
                $metadata
            )]);

            $this->clearCache($key);
        });
    }

    public function track(string $key, mixed $value, array $metadata = []): void
    {
        $this->create([
            'key' => $key,
            'value' => $value,
            'metadata' => $metadata,
            'tracked_at' => now()
        ]);

        $this->clearCache($key);
    }

    public function getStats(string $key, array $options = []): array
    {
        $cacheKey = $this->getCacheKey("stats:{$key}");

        return Cache::remember($cacheKey, $this->cacheDuration, function() use ($key, $options) {
            $query = $this->query()->where('key', $key);

            if (isset($options['from'])) {
                $query->where('tracked_at', '>=', $options['from']);
            }

            if (isset($options['to'])) {
                $query->where('tracked_at', '<=', $options['to']);
            }

            return [
                'total' => $query->sum('value'),
                'average' => $query->avg('value'),
                'min' => $query->min('value'),
                'max' => $query->max('value'),
                'count' => $query->count()
            ];
        });
    }

    public function getTimeSeries(string $key, string $interval = '1 day'): Collection
    {
        return $this->query()
            ->where('key', $key)
            ->select(DB::raw("
                DATE_TRUNC('{$interval}', tracked_at) as period,
                SUM(value) as total,
                AVG(value) as average,
                COUNT(*) as count
            "))
            ->groupBy('period')
            ->orderBy('period')
            ->get();
    }

    protected function getOrCreateStat(string $key): Model
    {
        $stat = $this->query()->firstOrCreate(
            ['key' => $key],
            ['count' => 0]
        );

        return $stat;
    }

    protected function getCacheKey(string $key): string
    {
        return $this->cachePrefix . $key;
    }

    protected function clearCache(string $key): void
    {
        Cache::forget($this->getCacheKey("stats:{$key}"));
        Cache::forget($this->getCacheKey("series:{$key}"));
    }
}

class MetricsRepository extends Repository
{
    public function recordMetric(string $name, float $value, array $tags = []): void
    {
        $this->create([
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
            'recorded_at' => now()
        ]);
    }

    public function getMetrics(string $name, array $filters = []): Collection
    {
        $query = $this->query()->where('name', $name);

        if (!empty($filters['tags'])) {
            foreach ($filters['tags'] as $tag => $value) {
                $query->whereJsonContains("tags->{$tag}", $value);
            }
        }

        return $query->orderBy('recorded_at')->get();
    }

    public function getAggregates(string $name, string $function = 'avg'): array
    {
        return $this->query()
            ->where('name', $name)
            ->select(
                'name',
                DB::raw("$function(value) as aggregate_value")
            )
            ->groupBy('name')
            ->first()
            ?->toArray() ?? [];
    }
}

class AnalyticsRepository extends Repository
{
    public function trackPageview(array $data): void
    {
        $this->create(array_merge($data, [
            'type' => 'pageview',
            'session_id' => session()->getId(),
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'tracked_at' => now()
        ]));
    }

    public function trackEvent(string $category, string $action, array $data = []): void
    {
        $this->create(array_merge($data, [
            'type' => 'event',
            'category' => $category,
            'action' => $action,
            'session_id' => session()->getId(),
            'user_id' => auth()->id(),
            'tracked_at' => now()
        ]));
    }

    public function getPageviews(array $filters = []): Collection
    {
        $query = $this->query()->where('type', 'pageview');

        if (isset($filters['path'])) {
            $query->where('path', $filters['path']);
        }

        if (isset($filters['from'])) {
            $query->where('tracked_at', '>=', $filters['from']);
        }

        return $query->orderBy('tracked_at')->get();
    }

    public function getEvents(string $category, array $filters = []): Collection
    {
        return $this->query()
            ->where('type', 'event')
            ->where('category', $category)
            ->when(isset($filters['action']), function($query) use ($filters) {
                $query->where('action', $filters['action']);
            })
            ->orderBy('tracked_at')
            ->get();
    }
}
