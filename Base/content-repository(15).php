<?php

namespace App\Repositories;

use App\Core\Repositories\BaseRepository;
use App\Models\Content;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class ContentRepository extends BaseRepository
{
    protected function model(): string
    {
        return Content::class;
    }

    public function findPublished(): Collection
    {
        return $this->newQuery()
            ->where('status', 'published')
            ->where('published_at', '<=', Carbon::now())
            ->orderBy('published_at', 'desc')
            ->get();
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->newQuery()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->where('published_at', '<=', Carbon::now())
            ->first();
    }

    public function findByCategory(int $categoryId): Collection
    {
        return $this->newQuery()
            ->where('category_id', $categoryId)
            ->where('status', 'published')
            ->where('published_at', '<=', Carbon::now())
            ->orderBy('published_at', 'desc')
            ->get();
    }

    public function findFeatured(int $limit = 5): Collection
    {
        return $this->newQuery()
            ->where('status', 'published')
            ->where('is_featured', true)
            ->where('published_at', '<=', Carbon::now())
            ->orderBy('published_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function countByStatus(string $status): int
    {
        return $this->newQuery()
            ->where('status', $status)
            ->count();
    }

    public function findScheduled(): Collection
    {
        return $this->newQuery()
            ->where('status', 'published')
            ->where('published_at', '>', Carbon::now())
            ->orderBy('published_at', 'asc')
            ->get();
    }

    public function searchContent(string $query): Collection
    {
        return $this->newQuery()
            ->where('status', 'published')
            ->where('published_at', '<=', Carbon::now())
            ->where(function($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('content', 'like', "%{$query}%")
                  ->orWhere('excerpt', 'like', "%{$query}%");
            })
            ->orderBy('published_at', 'desc')
            ->get();
    }
}
