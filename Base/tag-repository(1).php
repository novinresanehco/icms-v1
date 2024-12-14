<?php

namespace App\Repositories;

use App\Models\Tag;
use App\Repositories\Contracts\TagRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class TagRepository extends BaseRepository implements TagRepositoryInterface 
{
    protected array $searchableFields = ['name', 'slug', 'description'];
    protected array $filterableFields = ['status', 'type'];

    /**
     * Get popular tags with usage count
     */
    public function getPopularTags(int $limit = 20): Collection
    {
        $cacheKey = 'tags.popular.' . $limit;

        return Cache::tags(['tags'])->remember($cacheKey, 3600, function() use ($limit) {
            return $this->model
                ->withCount('content')
                ->orderByDesc('content_count')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Find or create tags from array of names
     */
    public function findOrCreate(array $tagNames): Collection
    {
        $tags = collect();

        foreach ($tagNames as $name) {
            $tag = $this->model->firstOrCreate(
                ['name' => trim($name)],
                ['slug' => str_slug(trim($name))]
            );
            $tags->push($tag);
        }

        Cache::tags(['tags'])->flush();

        return $tags;
    }

    /**
     * Get related tags based on content associations
     */
    public function getRelatedTags(Tag $tag, int $limit = 10): Collection
    {
        $cacheKey = 'tags.related.' . $tag->id . '.' . $limit;

        return Cache::tags(['tags'])->remember($cacheKey, 3600, function() use ($tag, $limit) {
            return $this->model
                ->whereHas('content', function($query) use ($tag) {
                    $query->whereHas('tags', function($q) use ($tag) {
                        $q->where('tags.id', $tag->id);
                    });
                })
                ->where('id', '!=', $tag->id)
                ->withCount('content')
                ->orderByDesc('content_count')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Get tags by type with content count
     */
    public function getByType(string $type): Collection
    {
        $cacheKey = 'tags.type.' . $type;

        return Cache::tags(['tags'])->remember($cacheKey, 3600, function() use ($type) {
            return $this->model
                ->where('type', $type)
                ->withCount('content')
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Merge tags and update content associations
     */
    public function mergeTags(Tag $sourceTag, Tag $targetTag): bool
    {
        try {
            // Update content associations
            $sourceTag->content()->sync(
                $targetTag->content()->pluck('content.id')->toArray()
            );

            // Delete source tag
            $sourceTag->delete();

            Cache::tags(['tags'])->flush();

            return true;
        } catch (\Exception $e) {
            \Log::error('Error merging tags: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find tag by slug with caching
     */
    public function findBySlug(string $slug): ?Tag
    {
        $cacheKey = 'tags.slug.' . $slug;

        return Cache::tags(['tags'])->remember($cacheKey, 3600, function() use ($slug) {
            return $this->model
                ->where('slug', $slug)
                ->first();
        });
    }

    /**
     * Get unused tags
     */
    public function getUnusedTags(): Collection
    {
        return $this->model
            ->has('content', '=', 0)
            ->get();
    }

    /**
     * Clean unused tags
     */
    public function cleanUnusedTags(): int
    {
        $count = $this->getUnusedTags()->count();
        
        $this->model
            ->has('content', '=', 0)
            ->delete();

        Cache::tags(['tags'])->flush();

        return $count;
    }
}
