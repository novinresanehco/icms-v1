<?php

namespace App\Core\Services;

use App\Core\Models\Category;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Collection;

class CategoryCacheService
{
    protected array $config;
    protected array $tags;

    public function __construct()
    {
        $this->config = config('cache.categories');
        $this->tags = $this->config['tags'];
    }

    public function rememberCategory(int $id, Category $category): Category
    {
        return Cache::tags($this->tags)
            ->remember(
                $this->getCategoryKey($id),
                $this->config['ttl'],
                fn() => $category
            );
    }

    public function rememberTree(callable $callback): Collection
    {
        return Cache::tags($this->tags)
            ->remember(
                $this->config['keys']['tree'],
                $this->config['ttl'],
                $callback
            );
    }

    public function rememberRoots(callable $callback): Collection
    {
        return Cache::tags($this->tags)
            ->remember(
                $this->config['keys']['roots'],
                $this->config['ttl'],
                $callback
            );
    }

    public function rememberByType(string $type, callable $callback): Collection
    {
        return Cache::tags($this->tags)
            ->remember(
                $this->config['keys']['types'] . $type,
                $this->config['ttl'],
                $callback
            );
    }

    public function rememberChildren(int $categoryId, callable $callback): Collection
    {
        return Cache::tags($this->tags)
            ->remember(
                $this->config['keys']['children'] . $categoryId,
                $this->config['ttl'],
                $callback
            );
    }

    public function forget(int $id): bool
    {
        return Cache::tags($this->tags)->forget($this->getCategoryKey($id));
    }

    public function flush(): bool
    {
        return Cache::tags($this->tags)->flush();
    }

    protected function getCategoryKey(int $id): string
    {
        return $this->config['keys']['item'] . $id;
    }
}
