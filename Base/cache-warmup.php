<?php

namespace App\Core\Repositories\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Console\Command;

class CacheWarmer
{
    protected array $config;
    protected array $warmupStrategies;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'batch_size' => 100,
            'parallel_jobs' => 3,
            'priority_threshold' => 0.8
        ], $config);

        $this->registerDefaultStrategies();
    }

    public function warmup(array $models = []): array
    {
        $stats = [
            'started_at' => now(),
            'processed' => 0,
            'cached' => 0,
            'errors' => 0,
            'details' => []
        ];

        foreach ($models as $modelClass) {
            $strategy = $this->getWarmupStrategy($modelClass);
            $stats['details'][$modelClass] = $this->warmupModel($modelClass, $strategy);
        }

        $stats['completed_at'] = now();
        $stats['duration'] = $stats['completed_at']->diffInSeconds($stats['started_at']);
        $stats['processed'] = array_sum(array_column($stats['details'], 'processed'));
        $stats['cached'] = array_sum(array_column($stats['details'], 'cached'));
        $stats['errors'] = array_sum(array_column($stats['details'], 'errors'));

        return $stats;
    }

    protected function warmupModel(string $modelClass, WarmupStrategy $strategy): array
    {
        $stats = [
            'processed' => 0,
            'cached' => 0,
            'errors' => 0,
            'start_time' => microtime(true)
        ];

        try {
            $query = $strategy->getQuery($modelClass);
            $priorityItems = $strategy->getPriorityItems($modelClass);

            // Warm up priority items first
            foreach ($priorityItems as $item) {
                $this->warmupItem($item, $strategy, $stats);
            }

            // Warm up remaining items in batches
            $query->chunkById($this->config['batch_size'], function ($chunk) use ($strategy, &$stats) {
                foreach ($chunk as $item) {
                    $this->warmupItem($item, $strategy, $stats);
                }
            });

        } catch (\Exception $e) {
            $stats['errors']++;
            Log::error('Cache warmup failed for model: ' . $modelClass, [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        $stats['duration'] = microtime(true) - $stats['start_time'];
        return $stats;
    }

    protected function warmupItem(Model $item, WarmupStrategy $strategy, array &$stats): void
    {
        try {
            $cacheKeys = $strategy->getCacheKeys($item);
            $data = $strategy->getData($item);

            foreach ($cacheKeys as $key) {
                Cache::tags($strategy->getCacheTags($item))
                    ->put($key, $data, $strategy->getTTL($item));
            }

            $stats['cached']++;
        } catch (\Exception $e) {
            $stats['errors']++;
            Log::error('Failed to warm up cache for item', [
                'model' => get_class($item),
                'id' => $item->getKey(),
                'error' => $e->getMessage()
            ]);
        }

        $stats['processed']++;
    }

    protected function registerDefaultStrategies(): void
    {
        $this->warmupStrategies = [
            'default' => new DefaultWarmupStrategy(),
            'content' => new ContentWarmupStrategy(),
            'user' => new UserWarmupStrategy(),
            'settings' => new SettingsWarmupStrategy()
        ];
    }

    protected function getWarmupStrategy(string $modelClass): WarmupStrategy
    {
        $strategyKey = $this->config['strategies'][$modelClass] ?? 'default';
        return $this->warmupStrategies[$strategyKey];
    }
}

abstract class WarmupStrategy
{
    abstract public function getQuery(string $modelClass);
    abstract public function getPriorityItems(string $modelClass): Collection;
    abstract public function getCacheKeys(Model $item): array;
    abstract public function getData(Model $item): array;
    abstract public function getCacheTags(Model $item): array;
    abstract public function getTTL(Model $item): int;
}

class ContentWarmupStrategy extends WarmupStrategy
{
    public function getQuery(string $modelClass)
    {
        return $modelClass::query()
            ->where('status', 'published')
            ->with(['author', 'categories', 'tags']);
    }

    public function getPriorityItems(string $modelClass): Collection
    {
        return $modelClass::query()
            ->where('status', 'published')
            ->where('priority', '>=', 0.8)
            ->orWhere('views_count', '>=', 1000)
            ->get();
    }

    public function getCacheKeys(Model $item): array
    {
        return [
            "content:{$item->id}",
            "content:slug:{$item->slug}",
            "content:related:{$item->id}"
        ];
    }

    public function getData(Model $item): array
    {
        return [
            'content' => $item->toArray(),
            'related' => $item->getRelated()->toArray(),
            'metadata' => $this->getMetadata($item)
        ];
    }

    public function getCacheTags(Model $item): array
    {
        return [
            'content',
            "content:{$item->id}",
            ...collect($item->categories)->pluck('slug')->toArray()
        ];
    }

    public function getTTL(Model $item): int
    {
        return $item->priority >= 0.8 ? 3600 : 7200;
    }

    protected function getMetadata(Model $item): array
    {
        return [
            'last_updated' => $item->updated_at,
            'cache_generated' => now(),
            'priority' => $item->priority
        ];
    }
}
