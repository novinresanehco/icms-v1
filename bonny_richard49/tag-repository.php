<?php

namespace App\Core\Tag\Repository;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Events\TagCreated;
use App\Core\Tag\Events\TagUpdated;
use App\Core\Tag\Events\TagDeleted;
use App\Core\Tag\Events\TagsMerged;
use App\Core\Tag\Exceptions\TagNotFoundException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

class TagRepository extends BaseRepository implements TagRepositoryInterface
{
    protected const CACHE_KEY = 'tags';
    protected const CACHE_TTL = 3600; // 1 hour

    public function __construct(CacheManagerInterface $cache)
    {
        parent::__construct($cache);
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return Tag::class;
    }

    public function findBySlug(string $slug): ?Tag
    {
        return $this->cache->remember(
            $this->getCacheKey("slug:{$slug}"),
            fn() => $this->model->where('slug', $slug)->first()
        );
    }

    public function findOrCreateByName(string $name): Tag
    {
        DB::beginTransaction();
        try {
            $slug = Str::slug($name);
            $tag = $this->findBySlug($slug);

            if (!$tag) {
                $tag = $this->model->create([
                    'name' => $name,
                    'slug' => $slug
                ]);

                Event::dispatch(new TagCreated($tag));
                $this->clearCache();
            }

            DB::commit();
            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getMostUsed(int $limit = 10): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("most_used:{$limit}"),
            fn() => $this->model->withCount('contents')
                               ->orderBy('contents_count', 'desc')
                               ->limit($limit)
                               ->get()
        );
    }

    public function getForContent(int $contentId): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("content:{$contentId}"),
            fn() => $this->model->whereHas('contents', function($query) use ($contentId) {
                $query->where('content.id', $contentId);
            })->get()
        );
    }

    public function syncWithContent(int $contentId, array $tagIds): void
    {
        DB::beginTransaction();
        try {
            $content = app(ContentRepositoryInterface::class)->findOrFail($contentId);
            $content->tags()->sync($tagIds);
            
            $this->clearCache();
            $this->clearContentTagsCache($contentId);
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getRelated(int $tagId, int $limit = 5): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("related:{$tagId}:{$limit}"),
            function() use ($tagId, $limit) {
                $tag = $this->findOrFail($tagId);
                
                return $this->model->whereHas('contents', function($query) use ($tag) {
                    $query->whereIn('content.id', $tag->contents->pluck('id'));
                })
                ->where('id', '!=', $tagId)
                ->withCount(['contents' => function($query) use ($tag) {
                    $query->whereIn('content.id', $tag->contents->pluck('id'));
                }])
                ->orderBy('contents_count', 'desc')
                ->limit($limit)
                ->get();
            }
        );
    }

    public function search(string $query): Collection
    {
        // Search is not cached as it's dynamic
        return $this->model->where('name', 'LIKE', "%{$query}%")
                          ->orWhere('slug', 'LIKE', "%{$query}%")
                          ->get();
    }

    public function getUsageStats(int $tagId): array
    {
        return $this->cache->remember(
            $this->getCacheKey("stats:{$tagId}"),
            function() use ($tagId) {
                $tag = $this->findOrFail($tagId);
                
                return [
                    'total_usage' => $tag->contents()->count(),
                    'published_content' => $tag->contents()->where('status', 'published')->count(),
                    'draft_content' => $tag->contents()->where('status', 'draft')->count(),
                    'monthly_usage' => $this->getMonthlyUsageStats($tag),
                    'category_usage' => $this->getCategoryUsageStats($tag),
                ];
            }
        );
    }

    public function mergeTags(int $sourceTagId, int $targetTagId): Tag
    {
        DB::beginTransaction();
        try {
            $sourceTag = $this->findOrFail($sourceTagId);
            $targetTag = $this->findOrFail($targetTagId);

            // Move all content associations
            $sourceTag->contents()->update(['tag_id' => $targetTagId]);

            // Dispatch event before deletion
            Event::dispatch(new TagsMerged($sourceTag, $targetTag));

            // Delete source tag
            $sourceTag->delete();

            // Clear cache
            $this->clearCache();

            DB::commit();
            return $targetTag->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function getMonthlyUsageStats(Tag $tag): array
    {
        return $tag->contents()
                   ->selectRaw('COUNT(*) as count, MONTH(created_at) as month')
                   ->whereYear('created_at', date('Y'))
                   ->groupBy('month')
                   ->get()
                   ->pluck('count', 'month')
                   ->toArray();
    }

    protected function getCategoryUsageStats(Tag $tag): array
    {
        return $tag->contents()
                   ->selectRaw('COUNT(*) as count, categories.name as category')
                   ->join('categories', 'content.category_id', '=', 'categories.id')
                   ->groupBy('categories.id', 'categories.name')
                   ->get()
                   ->pluck('count', 'category')
                   ->toArray();
    }

    protected function clearContentTagsCache(int $contentId): void
    {
        $this->cache->delete($this->getCacheKey("content:{$contentId}"));
    }

    public function clearCache(): void
    {
        $this->cache->tags([$this->getCacheKey()])->flush();
    }
}
