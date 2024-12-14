<?php

namespace App\Core\Repositories;

use App\Models\Tag;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class TagRepository extends AdvancedRepository
{
    protected $model = Tag::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function findOrCreate(string $name): Tag
    {
        return $this->executeTransaction(function() use ($name) {
            $tag = $this->model->firstOrCreate([
                'name' => $name,
                'slug' => str_slug($name)
            ]);
            $this->cache->forget('tags.all');
            return $tag;
        });
    }

    public function getPopular(int $limit = 10): Collection
    {
        return $this->executeQuery(function() use ($limit) {
            return $this->cache->remember("tags.popular.{$limit}", function() use ($limit) {
                return $this->model
                    ->withCount('contents')
                    ->orderByDesc('contents_count')
                    ->limit($limit)
                    ->get();
            });
        });
    }

    public function syncTags($taggable, array $tags): void
    {
        $this->executeTransaction(function() use ($taggable, $tags) {
            $tagIds = collect($tags)->map(function($tag) {
                return $this->findOrCreate($tag)->id;
            });
            
            $taggable->tags()->sync($tagIds);
            $this->cache->forget("taggable.{$taggable->id}.tags");
        });
    }

    public function mergeTags(Tag $source, Tag $target): void
    {
        $this->executeTransaction(function() use ($source, $target) {
            $source->contents()->detach();
            $source->delete();
            $this->cache->forget(['tags.all', 'tags.popular.*']);
        });
    }
}
