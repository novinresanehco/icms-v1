// app/Core/Cache/Decorators/CacheableDecorator.php
<?php

namespace App\Core\Cache\Decorators;

use App\Core\Cache\Contracts\Cacheable;
use App\Core\Cache\CacheManager;

class CacheableDecorator
{
    public function __construct(
        private CacheManager $cache,
        private object $subject
    ) {}

    public function __call(string $method, array $arguments)
    {
        if (!$this->isCacheable()) {
            return $this->subject->$method(...$arguments);
        }

        return $this->cache
            ->tags($this->getCacheTags())
            ->remember(
                $this->getCacheKey($method, $arguments),
                fn() => $this->subject->$method(...$arguments),
                $this->getCacheTtl()
            );
    }

    private function isCacheable(): bool
    {
        return $this->subject instanceof Cacheable;
    }

    private function getCacheKey(string $method, array $arguments): string
    {
        if ($this->isCacheable()) {
            return $this->subject->getCacheKey();
        }

        return sprintf(
            '%s_%s_%s',
            get_class($this->subject),
            $method,
            md5(serialize($arguments))
        );
    }

    private function getCacheTags(): array
    {
        if ($this->isCacheable()) {
            return $this->subject->getCacheTags();
        }

        return [get_class($this->subject)];
    }

    private function getCacheTtl(): ?int
    {
        if ($this->isCacheable()) {
            return $this->subject->getCacheTtl();
        }

        return config('cache.ttl', 3600);
    }
}

// app/Core/Cache/Decorators/QueryCacheDecorator.php
<?php

namespace App\Core\Cache\Decorators;

use Illuminate\Database\Query\Builder;
use App\Core\Cache\CacheManager;
use App\Core\Cache\CacheKeyGenerator;

class QueryCacheDecorator
{
    public function __construct(
        private CacheManager $cache,
        private Builder $query,
        private array $tags = []
    ) {}

    public function get(array $columns = ['*']): mixed
    {
        return $this->cache
            ->tags($this->getTags())
            ->remember(
                $this->getCacheKey($columns),
                fn() => $this->query->get($columns)
            );
    }

    public function first(array $columns = ['*']): mixed
    {
        return $this->cache
            ->tags($this->getTags())
            ->remember(
                $this->getCacheKey($columns, 'first'),
                fn() => $this->query->first($columns)
            );
    }

    public function count(string $columns = '*'): int
    {
        return $this->cache
            ->tags($this->getTags())
            ->remember(
                $this->getCacheKey([$columns], 'count'),
                fn() => $this->query->count($columns)
            );
    }

    private function getTags(): array
    {
        return array_merge(
            ['queries'],
            $this->tags
        );
    }

    private function getCacheKey(array $columns, ?string $suffix = null): string
    {
        return CacheKeyGenerator::generate('query', [
            'sql' => $this->query->toSql(),
            'bindings' => $this->query->getBindings(),
            'columns' => $columns,
            'suffix' => $suffix
        ]);
    }
}

// app/Core/Cache/Decorators/ModelCacheDecorator.php
<?php

namespace App\Core\Cache\Decorators;

use Illuminate\Database\Eloquent\Model;
use App\Core\Cache\CacheManager;
use App\Core\Cache\CacheKeyGenerator;

class ModelCacheDecorator
{
    public function __construct(
        private CacheManager $cache,
        private Model $model
    ) {}

    public function find(mixed $id): ?Model
    {
        return $this->cache
            ->tags($this->getTags())
            ->remember(
                $this->getCacheKey('find', [$id]),
                fn() => $this->model->find($id)
            );
    }

    public function all(): mixed
    {
        return $this->cache
            ->tags($this->getTags())
            ->remember(
                $this->getCacheKey('all'),
                fn() => $this->model->all()
            );
    }

    private function getTags(): array
    {
        return [
            'models',
            get_class($this->model)
        ];
    }

    private function getCacheKey(string $method, array $params = []): string
    {
        return CacheKeyGenerator::generate(
            strtolower(class_basename($this->model)),
            array_merge([$method], $params)
        );
    }
}