<?php

namespace App\Repositories;

use App\Core\Repositories\BaseRepository;
use App\Models\Content;
use App\Core\Contracts\ContentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    protected array $searchable = [
        'title',
        'content',
        'meta_description',
        'tags'
    ];

    protected array $with = [
        'author',
        'categories',
        'media'
    ];

    public function model(): string
    {
        return Content::class;
    }

    public function published(array $columns = ['*']): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('columns'));
        
        return $this->remember($cacheKey, function () use ($columns) {
            $query = $this->model->with($this->with)
                ->where('status', 'published')
                ->where('published_at', '<=', now());
                
            $this->performanceManager->monitorQueryPerformance();
            return $query->get($columns);
        });
    }

    public function findBySlug(string $slug): ?Content
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('slug'));
        
        return $this->remember($cacheKey, function () use ($slug) {
            $query = $this->model->with($this->with)
                ->where('slug', $slug)
                ->where('status', 'published')
                ->where('published_at', '<=', now());
                
            $this->performanceManager->monitorQueryPerformance();
            return $query->first();
        });
    }

    public function findByCategory(int $categoryId, int $perPage = 15): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('categoryId', 'perPage'));
        
        return $this->remember($cacheKey, function () use ($categoryId, $perPage) {
            $query = $this->model->with($this->with)
                ->whereHas('categories', function ($query) use ($categoryId) {
                    $query->where('id', $categoryId);
                })
                ->where('status', 'published')
                ->where('published_at', '<=', now());
                
            $this->performanceManager->monitorQueryPerformance();
            return $query->paginate($perPage);
        });
    }

    public function findByTag(string $tag, int $perPage = 15): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('tag', 'perPage'));
        
        return $this->remember($cacheKey, function () use ($tag, $perPage) {
            $query = $this->model->with($this->with)
                ->where('tags', 'LIKE', "%{$tag}%")
                ->where('status', 'published')
                ->where('published_at', '<=', now());
                
            $this->performanceManager->monitorQueryPerformance();
            return $query->paginate($perPage);
        });
    }

    public function findRelated(Content $content, int $limit = 5): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, ['id' => $content->id, 'limit' => $limit]);
        
        return $this->remember($cacheKey, function () use ($content, $limit) {
            $query = $this->model->with($this->with)
                ->where('id', '!=', $content->id)
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->whereHas('categories', function ($query) use ($content) {
                    $query->whereIn('id', $content->categories->pluck('id'));
                });
                
            $this->performanceManager->monitorQueryPerformance();
            return $query->limit($limit)->get();
        });
    }

    public function updateStatus(int $id, string $status): bool
    {
        $model = $this->find($id);
        
        if (!$model) {
            throw new RepositoryException("Content with ID {$id} not found");
        }
        
        $updated = $model->update(['status' => $status]);
        $this->clearCache();
        
        return $updated;
    }

    public function getPopular(int $limit = 5): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('limit'));
        
        return $this->remember($cacheKey, function () use ($limit) {
            $query = $this->model->with($this->with)
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->orderBy('views', 'desc');
                
            $this->performanceManager->monitorQueryPerformance();
            return $query->limit($limit)->get();
        });
    }

    public function incrementViews(int $id): bool
    {
        $model = $this->find($id);
        
        if (!$model) {
            throw new RepositoryException("Content with ID {$id} not found");
        }
        
        $updated = $model->increment('views');
        $this->clearCache();
        
        return $updated;
    }
}
