<?php

namespace App\Repositories;

use App\Models\Tag;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class TagRepository extends BaseRepository
{
    public function __construct(Tag $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findBySlug(string $slug): ?Tag
    {
        return $this->executeWithCache(__FUNCTION__, [$slug], function () use ($slug) {
            return $this->model->where('slug', $slug)->first();
        });
    }

    public function findPopular(int $limit = 10): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$limit], function () use ($limit) {
            return $this->model->withCount('contents')
                             ->orderBy('contents_count', 'desc')
                             ->limit($limit)
                             ->get();
        });
    }

    public function syncTags(int $contentId, array $tags): void
    {
        $tagIds = [];
        foreach ($tags as $tagName) {
            $tag = $this->firstOrCreate(['name' => $tagName]);
            $tagIds[] = $tag->id;
        }

        $content = app(ContentRepository::class)->find($contentId);
        $content->tags()->sync($tagIds);
        
        $this->clearCache();
    }

    public function findRelated(Tag $tag, int $limit = 5): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$tag->id, $limit], function () use ($tag, $limit) {
            return $this->model->whereHas('contents', function ($query) use ($tag) {
                $query->whereIn('content_id', $tag->contents->pluck('id'));
            })
            ->where('id', '!=', $tag->id)
            ->withCount('contents')
            ->orderBy('contents_count', 'desc')
            ->limit($limit)
            ->get();
        });
    }

    public function findOrCreate(string $name): Tag
    {
        $tag = $this->model->firstOrCreate(
            ['name' => $name],
            ['slug' => str_slug($name)]
        );
        
        $this->clearCache();
        return $tag;
    }
}
