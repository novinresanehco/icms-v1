<?php

namespace App\Core\Repositories;

use App\Models\Cache;
use Illuminate\Support\Collection;

class CacheRepository extends AdvancedRepository
{
    protected $model = Cache::class;
    
    public function remember(string $key, $data, int $ttl = 3600): void
    {
        $this->executeTransaction(function() use ($key, $data, $ttl) {
            $this->model->updateOrCreate(
                ['key' => $key],
                [
                    'value' => serialize($data),
                    'expires_at' => now()->addSeconds($ttl)
                ]
            );
        });
    }

    public function forget(string $key): void
    {
        $this->model->where('key', $key)->delete();
    }

    public function get(string $key)
    {
        $cache = $this->executeQuery(function() use ($key) {
            return $this->model
                ->where('key', $key)
                ->where('expires_at', '>', now())
                ->first();
        });

        return $cache ? unserialize($cache->value) : null;
    }

    public function cleanup(): int
    {
        return $this->executeTransaction(function() {
            return $this->model->where('expires_at', '<=', now())->delete();
        });
    }

    public function tags(array $tags): Collection
    {
        return $this->executeQuery(function() use ($tags) {
            return $this->model
                ->whereJsonContains('tags', $tags)
                ->where('expires_at', '>', now())
                ->get();
        });
    }

    public function flush(): void
    {
        $this->executeTransaction(function() {
            $this->model->truncate();
        });
    }
}
