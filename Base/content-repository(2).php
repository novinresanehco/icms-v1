<?php

namespace App\Repositories;

use App\Models\Content;
use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    protected array $searchableFields = [
        'title', 
        'slug', 
        'content', 
        'meta_description'
    ];
    
    protected array $filterableFields = [
        'status',
        'type',
        'category_id',
        'author_id',
        'language'
    ];

    /**
     * Get published content items with optional pagination
     *
     * @param int $perPage
     * @param array $relations
     * @return LengthAwarePaginator
     */
    public function getPublished(int $perPage = 15, array $relations = []): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('status', 'published')
            ->where('published_at', '<=', now())
            ->with($relations)
            ->orderByDesc('published_at')
            ->paginate($perPage);
    }

    /**
     * Get content by slug ensuring it's published
     *
     * @param string $slug
     * @param array $relations
     * @return Content|null
     */
    public function getPublishedBySlug(string $slug, array $relations = []): ?Content
    {
        $cacheKey = "content.published.{$slug}";

        return Cache::tags(['content'])->remember($cacheKey, 3600, function() use ($slug, $relations) {
            return $this->model->newQuery()
                ->where('slug', $slug)
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->with($relations)
                ->first();
        });
    }

    /**
     * Get content items by category
     *
     * @param int $categoryId
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getByCategory(int $categoryId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->where('category_id', $categoryId)
            ->where('status', 'published')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->paginate($perPage);
    }

    /**
     * Schedule content for publishing
     *
     * @param int $id
     * @param string $publishAt
     * @return bool
     */
    public function schedulePublish(int $id, string $publishAt): bool
    {
        try {
            $content = $this->find($id);
            
            if (!$content) {
                return false;
            }

            $this->update($id, [
                'status' => 'scheduled',
                'published_at' => $publishAt
            ]);

            Cache::tags(['content'])->forget("content.published.{$content->slug}");
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Error scheduling content: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get content statistics
     *
     * @return array
     */
    public function getContentStats(): array
    {
        $cacheKey = 'content.stats';

        return Cache::tags(['content'])->remember($cacheKey, 300, function() {
            return [
                'draft' => $this->model->where('status', 'draft')->count(),
                'published' => $this->model->where('status', 'published')->count(),
                'scheduled' => $this->model->where('status', 'scheduled')->count(),
                'by_category' => $this->model->groupBy('category_id')
                    ->selectRaw('category_id, count(*) as count')
                    ->pluck('count', 'category_id')
                    ->toArray(),
                'by_author' => $this->model->groupBy('author_id')
                    ->selectRaw('author_id, count(*) as count')
                    ->pluck('count', 'author_id')
                    ->toArray()
            ];
        });
    }

    /**
     * Get related content items
     *
     * @param int $contentId
     * @param int $limit
     * @return Collection
     */
    public function getRelated(int $contentId, int $limit = 5): Collection
    {
        $content = $this->find($contentId);
        
        if (!$content) {
            return collect([]);
        }

        return $this->model->newQuery()
            ->where('id', '!=', $contentId)
            ->where('category_id', $content->category_id)
            ->where('status', 'published')
            ->where('published_at', '<=', now())
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Update content status
     *
     * @param int $id
     * @param string $status
     * @return bool
     */
    public function updateStatus(int $id, string $status): bool
    {
        try {
            $content = $this->find($id);
            
            if (!$content) {
                return false;
            }

            $this->update($id, [
                'status' => $status,
                'published_at' => $status === 'published' ? now() : null
            ]);

            Cache::tags(['content'])->forget("content.published.{$content->slug}");
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Error updating content status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Search content with advanced filters
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function advancedSearch(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        if (!empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query->where(function($q) use ($searchTerm) {
                $q->where('title', 'like', "%{$searchTerm}%")
                  ->orWhere('content', 'like', "%{$searchTerm}%")
                  ->orWhere('meta_description', 'like', "%{$searchTerm}%");
            });
        }

        foreach ($this->filterableFields as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
