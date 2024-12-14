<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\TagRepositoryInterface;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    public function __construct(Tag $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug): ?Tag
    {
        return Cache::tags(['tags'])->remember(
            "tag:slug:{$slug}",
            now()->addDay(),
            fn () => $this->model->where('slug', $slug)->first()
        );
    }

    public function getPopularTags(int $limit = 10): Collection
    {
        return Cache::tags(['tags', 'popular'])->remember(
            "tags:popular:{$limit}",
            now()->addHours(6),
            fn () => $this->model
                ->withCount('contents')
                ->orderByDesc('contents_count')
                ->limit($limit)
                ->get()
        );
    }

    public function findOrCreate(string $name, ?string $slug = null): Tag
    {
        $slug = $slug ?? \Str::slug($name);
        
        return $this->model->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name]
        );
    }

    public function syncTags(int $contentId, array $tags): void
    {
        $tagIds = collect($tags)->map(function ($tag) {
            return is_array($tag) 
                ? $this->findOrCreate($tag['name'], $tag['slug'])->id
                : $this->findOrCreate($tag)->id;
        });

        $this->model->find($contentId)->tags()->sync($tagIds);
        Cache::tags(['tags'])->flush();
    }

    public function getRelatedTags(int $tagId, int $limit = 5): Collection
    {
        return Cache::tags(['tags', "related:{$tagId}"])->remember(
            "tags:related:{$tagId}:{$limit}",
            now()->addHours(12),
            fn () => $this->model
                ->whereHas('contents', function ($query) use ($tagId) {
                    $query->whereHas('tags', function ($q) use ($tagId) {
                        $q->where('tags.id', $tagId);
                    });
                })
                ->where('id', '!=', $tagId)
                ->withCount('contents')
                ->orderByDesc('contents_count')
                ->limit($limit)
                ->get()
        );
    }

    public function searchTags(string $query): Collection
    {
        return $this->model
            ->where('name', 'LIKE', "%{$query}%")
            ->orWhere('slug', 'LIKE', "%{$query}%")
            ->orderBy('name')
            ->get();
    }
}
