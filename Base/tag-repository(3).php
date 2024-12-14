<?php

namespace App\Repositories;

use App\Models\Tag;
use App\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Support\Collection;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    protected array $searchableFields = ['name', 'slug'];
    protected array $filterableFields = ['type'];
    protected array $relationships = ['contents'];

    public function __construct(Tag $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug): ?Tag
    {
        return Cache::remember(
            $this->getCacheKey("slug.{$slug}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)->where('slug', $slug)->first()
        );
    }

    public function getPopular(int $limit = 10): Collection
    {
        return Cache::remember(
            $this->getCacheKey("popular.{$limit}"),
            $this->cacheTTL,
            fn() => $this->model->withCount('contents')
                ->orderBy('contents_count', 'desc')
                ->limit($limit)
                ->get()
        );
    }

    public function syncTags(int $contentId, array $tags): void
    {
        try {
            DB::beginTransaction();
            
            $content = Content::findOrFail($contentId);
            $tagIds = [];
            
            foreach ($tags as $tagName) {
                $tag = $this->model->firstOrCreate(
                    ['name' => $tagName],
                    ['slug' => Str::slug($tagName)]
                );
                $tagIds[] = $tag->id;
            }
            
            $content->tags()->sync($tagIds);
            
            DB::commit();
            $this->clearModelCache();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to sync tags: {$e->getMessage()}");
        }
    }
}
