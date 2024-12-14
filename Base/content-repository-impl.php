<?php

namespace App\Repositories;

use App\Core\Repositories\CacheableRepository;
use App\Models\Content;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ContentRepository extends CacheableRepository
{
    protected function model(): string
    {
        return Content::class;
    }

    protected function baseContentQuery(): Builder
    {
        return $this->newQuery()
            ->with(['category', 'author', 'tags'])
            ->where('status', 'published')
            ->where('published_at', '<=', Carbon::now());
    }

    public function findPublished(): Collection
    {
        return Cache::tags($this->cachePrefix)->remember(
            $this->getCacheKey(__FUNCTION__),
            $this->getCacheTTL(),
            fn() => $this->baseContentQuery()
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }

    public function findByCategory(int $categoryId): Collection
    {
        return Cache::tags($this->cachePrefix)->remember(
            $this->getCacheKey(__FUNCTION__, [$categoryId]),
            $this->getCacheTTL(),
            fn() => $this->baseContentQuery()
                ->where('category_id', $categoryId)
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }

    public function findBySlug(string $slug): ?Content
    {
        return Cache::tags($this->cachePrefix)->remember(
            $this->getCacheKey(__FUNCTION__, [$slug]),
            $this->getCacheTTL(),
            fn() => $this->baseContentQuery()
                ->where('slug', $slug)
                ->first()
        );
    }

    public function searchContent(string $query): Collection
    {
        return $this->baseContentQuery()
            ->where(function($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('content', 'like', "%{$query}%")
                  ->orWhereHas('tags', function($q) use ($query) {
                      $q->where('name', 'like', "%{$query}%");
                  });
            })
            ->orderBy('published_at', 'desc')
            ->get();
    }

    protected function afterCreate(Model $model, array $data): void
    {
        if (isset($data['tags'])) {
            $model->tags()->sync($data['tags']);
        }
    }

    protected function afterUpdate(Model $model, array $data): void
    {
        if (isset($data['tags'])) {
            $model->tags()->sync($data['tags']);
        }
    }
}
