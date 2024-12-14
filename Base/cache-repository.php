<?php

namespace App\Repositories;

use App\Models\Cache;
use App\Repositories\Contracts\CacheRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

class CacheRepository extends BaseRepository implements CacheRepositoryInterface 
{
    protected array $searchableFields = ['key', 'tags'];
    protected array $filterableFields = ['type'];

    public function get(string $key, $default = null)
    {
        $cache = $this->model->where('key', $key)
            ->where(function($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$cache) {
            return $default;
        }

        return $this->deserialize($cache->value);
    }

    public function put(string $key, $value, int $ttl = null): bool
    {
        try {
            $this->model->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $this->serialize($value),
                    'type' => $this->determineType($value),
                    'expires_at' => $ttl ? now()->addSeconds($ttl) : null,
                    'metadata' => [
                        'size' => strlen($this->serialize($value)),
                        'created_by' => auth()->id() ?? 'system'
                    ]
                ]
            );

            return true;
        } catch (\Exception $e) {
            \Log::error('Cache put error: ' . $e->getMessage());
            return false;
        }
    }

    public function forget(string $key): bool
    {
        return (bool) $this->model->where('key', $key)->delete();
    }

    public function tags(array $tags): self
    {
        $this->model = $this->model->whereJsonContains('tags', $tags);
        return $this;
    }

    public function flush(): bool
    {
        return (bool) $this->model->delete();
    }

    public function getExpired(): Collection
    {
        return $this->model
            ->where('expires_at', '<=', now())
            ->get();
    }

    public function clearExpired(): int
    {
        return $this->model
            ->where('expires_at', '<=', now())
            ->delete();
    }

    public function getMetrics(): array
    {
        return [
            'total_entries' => $this->model->count(),
            'expired_entries' => $this->model
                ->where('expires_at', '<=', now())
                ->count(),
            'total_size' => $this->model->sum('metadata->size'),
            'by_type' => $this->model
                ->groupBy('type')
                ->selectRaw('type, count(*) as count')
                ->pluck('count', 'type')
                ->toArray()
        ];
    }

    public function remember(string $key, int $ttl = null, callable $callback)
    {
        $value = $this->get($key);

        if (!is_null($value)) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    public function rememberForever(string $key, callable $callback)
    {
        return $this->remember($key, null, $callback);
    }

    public function putMany(array $values, int $ttl = null): bool
    {
        try {
            foreach ($values as $key => $value) {
                $this->put($key, $value, $ttl);
            }
            return true;
        } catch (\Exception $e) {
            \Log::error('Cache putMany error: ' . $e->getMessage());
            return false;
        }
    }

    public function getMany(array $keys): array
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }
        return $results;
    }

    protected function serialize($value): string
    {
        return serialize($value);
    }

    protected function deserialize(string $value)
    {
        return unserialize($value);
    }

    protected function determineType($value): string
    {
        if (is_array($value)) return 'array';
        if (is_object($value)) return 'object';
        if (is_string($value)) return 'string';
        if (is_numeric($value)) return 'numeric';
        if (is_bool($value)) return 'boolean';
        return 'other';
    }
}
