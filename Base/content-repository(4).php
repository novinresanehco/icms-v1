<?php

namespace App\Repositories;

use App\Models\Content;
use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    protected array $searchableFields = ['title', 'content', 'slug', 'meta_keywords'];
    protected array $filterableFields = ['status', 'type', 'category_id', 'author_id'];

    /**
     * Get published content with pagination
     *
     * @param int $perPage
     * @param array $with
     * @return LengthAwarePaginator
     */
    public function getPublished(int $perPage = 15, array $with = []): LengthAwarePaginator
    {
        return $this->model
            ->with($with)
            ->where('status', 'published')
            ->where('published_at', '<=', now())
            ->orderBy('published_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get content by slug with relations
     *
     * @param string $slug
     * @param array $with
     * @return Content|null
     */
    public function findBySlug(string $slug, array $with = []): ?Content
    {
        return $this->model
            ->with($with)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Get content statistics
     *
     * @return array
     */
    public function getContentStats(): array
    {
        return [
            'total' => $this->model->count(),
            'published' => $this->model->where('status', 'published')->count(),
            'draft' => $this->model->where('status', 'draft')->count(),
            'by_category' => $this->model
                ->groupBy('category_id')
                ->select('category_id', DB::raw('count(*) as count'))
                ->get()
                ->toArray(),
            'by_author' => $this->model
                ->groupBy('author_id')
                ->select('author_id', DB::raw('count(*) as count'))
                ->get()
                ->toArray()
        ];
    }

    /**
     * Get related content
     *
     * @param Content $content
     * @param int $limit
     * @return Collection
     */
    public function getRelated(Content $content, int $limit = 5): Collection
    {
        return $this->model
            ->where('id', '!=', $content->id)
            ->where('category_id', $content->category_id)
            ->where('status', 'published')
            ->limit($limit)
            ->get();
    }

    /**
     * Update content views
     *
     * @param int $id
     * @return bool
     */
    public function incrementViews(int $id): bool
    {
        return $this->model
            ->where('id', $id)
            ->increment('views');
    }
}
