<?php

namespace App\Core\Tag\Repository;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Core\Tag\Contracts\TagReadInterface;

class TagReadRepository implements TagReadInterface
{
    /**
     * @var Tag
     */
    protected Tag $model;

    public function __construct(Tag $model)
    {
        $this->model = $model;
    }

    /**
     * Find a tag by ID with caching.
     */
    public function findById(int $id, array $with = []): ?Tag
    {
        $cacheKey = "tag:{$id}:" . md5(serialize($with));

        return cache()->remember(
            $cacheKey,
            now()->addHour(),
            fn() => $this->model->with($with)->find($id)
        );
    }

    /**
     * Find tags by multiple IDs with caching.
     */
    public function findByIds(array $ids, array $with = []): Collection
    {
        $cacheKey = "tags:" . md5(serialize($ids) . serialize($with));

        return cache()->remember(
            $cacheKey,
            now()->addHour(),
            fn() => $this->model->with($with)->findMany($ids)
        );
    }

    /**
     * Search tags with pagination.
     */
    public function search(array $criteria, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery();

        // Apply search criteria
        if (!empty($criteria['search'])) {
            $query->where(function ($q) use ($criteria) {
                $q->where('name', 'like', "%{$criteria['search']}%")
                  ->orWhere('description', 'like', "%{$criteria['search']}%");
            });
        }

        // Apply filters
        if (!empty($criteria['filters'])) {
            foreach ($criteria['filters'] as $field => $value) {
                $query->where($field, $value);
            }
        }

        // Apply sorting
        if (!empty($criteria['sort'])) {
            $direction = $criteria['direction'] ?? 'asc';
            $query->orderBy($criteria['sort'], $direction);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get popular tags with caching.
     */
    public function getPopularTags(int $limit = 10): Collection
    {
        $cacheKey = "popular_tags:{$limit}";

        return cache()->remember(
            $cacheKey,
            now()->addHours(6),
            fn() => $this->model
                ->withCount('contents')
                ->orderByDesc('contents_count')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Get recent tags.
     */
    public function getRecentTags(int $limit = 10): Collection
    {
        return $this->model
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Get tag suggestions based on content.
     */
    public function getSuggestions(string $content, int $limit = 5): Collection
    {
        // Implement content analysis and tag suggestion logic
        return collect();
    }

    /**
     * Get tag statistics.
     */
    public function getStatistics(): array
    {
        $cacheKey = 'tag_statistics';

        return cache()->remember(
            $cacheKey,
            now()->addHour(),
            fn() => [
                'total_count' => $this->model->count(),
                'used_count' => $this->model->has('contents')->count(),
                'unused_count' => $this->model->doesntHave('contents')->count(),
                'average_usage' => $this->getAverageUsage()
            ]
        );
    }

    /**
     * Calculate average tag usage.
     */
    protected function getAverageUsage(): float
    {
        return $this->model
            ->withCount('contents')
            ->get()
            ->average('contents_count') ?? 0.0;
    }
}
