<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\ContentRepositoryInterface;
use App\Models\Content;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    public function __construct(Content $model)
    {
        parent::__construct($model);
    }

    public function findPublished(array $columns = ['*']): Collection
    {
        return Cache::tags(['content', 'published'])->remember(
            'published_content',
            now()->addHours(1),
            fn () => $this->model->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get($columns)
        );
    }

    public function findBySlug(string $slug): ?Content
    {
        return Cache::tags(['content', "slug:{$slug}"])->remember(
            "content:slug:{$slug}",
            now()->addDay(),
            fn () => $this->model->where('slug', $slug)->first()
        );
    }

    public function findByCategory(int $categoryId, array $columns = ['*']): Collection
    {
        return Cache::tags(['content', "category:{$categoryId}"])->remember(
            "content:category:{$categoryId}",
            now()->addHours(3),
            fn () => $this->model->where('category_id', $categoryId)
                ->where('status', 'published')
                ->orderBy('published_at', 'desc')
                ->get($columns)
        );
    }

    public function updateStatus(int $id, string $status): bool
    {
        $result = $this->update($id, ['status' => $status]);
        
        if ($result) {
            Cache::tags(['content'])->flush();
        }
        
        return $result;
    }

    public function searchContent(string $query): Collection
    {
        return $this->model
            ->where('title', 'LIKE', "%{$query}%")
            ->orWhere('content', 'LIKE', "%{$query}%")
            ->where('status', 'published')
            ->orderBy('published_at', 'desc')
            ->get();
    }

    public function getContentVersions(int $contentId): Collection
    {
        return $this->model->find($contentId)->versions()
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
