<?php

namespace App\Core\Repository;

use App\Models\Tag;
use App\Core\Events\TagEvents;
use App\Core\Exceptions\TagRepositoryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TagRepository extends BaseRepository
{
    protected const CACHE_TIME = 3600; // 1 hour
    
    protected function getModelClass(): string
    {
        return Tag::class;
    }

    /**
     * Create new tag
     */
    public function createTag(array $data): Tag
    {
        try {
            DB::beginTransaction();

            // Generate slug if not provided
            if (!isset($data['slug'])) {
                $data['slug'] = $this->generateUniqueSlug($data['name']);
            }

            $tag = $this->create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'general',
                'color' => $data['color'] ?? null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'created_by' => auth()->id()
            ]);

            if (!empty($data['metadata'])) {
                foreach ($data['metadata'] as $key => $value) {
                    $tag->metadata()->create([
                        'key' => $key,
                        'value' => $value
                    ]);
                }
            }

            DB::commit();
            $this->clearCache();
            event(new TagEvents\TagCreated($tag));

            return $tag;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagRepositoryException(
                "Failed to create tag: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get tags by content
     */
    public function getContentTags(int $contentId): Collection
    {
        return Cache::tags(['tags', "content.{$contentId}"])->remember(
            "content.{$contentId}.tags",
            self::CACHE_TIME,
            fn() => $this->model
                ->whereHas('contents', function($query) use ($contentId) {
                    $query->where('content.id', $contentId);
                })
                ->with('metadata')
                ->get()
        );
    }

    /**
     * Find tag by slug
     */
    public function findBySlug(string $slug): ?Tag
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("slug.{$slug}"),
            self::CACHE_TIME,
            fn() => $this->model
                ->where('slug', $slug)
                ->with(['metadata'])
                ->first()
        );
    }

    /**
     * Get popular tags
     */
    public function getPopularTags(int $limit = 10): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("popular.{$limit}"),
            self::CACHE_TIME,
            fn() => $this->model
                ->withCount('contents')
                ->orderBy('contents_count', 'desc')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Sync content tags
     */
    public function syncContentTags(int $contentId, array $tagIds): void
    {
        try {
            DB::beginTransaction();

            $content = app(ContentRepository::class)->find($contentId);
            if (!$content) {
                throw new TagRepositoryException("Content not found with ID: {$contentId}");
            }

            $content->tags()->sync($tagIds);
            
            DB::commit();
            
            // Clear related caches
            Cache::tags(["content.{$contentId}"])->flush();
            $this->clearCache();

            event(new TagEvents\ContentTagsSynced($content, $tagIds));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagRepositoryException(
                "Failed to sync content tags: {$e->getMessage()}"
            );
        }
    }

    /**
     * Search tags
     */
    public function searchTags(string $query): Collection
    {
        return $this->model
            ->where('name', 'LIKE', "%{$query}%")
            ->orWhere('description', 'LIKE', "%{$query}%")
            ->get();
    }

    /**
     * Generate unique slug
     */
    protected function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $count = 2;

        while ($this->model->where('slug', $slug)->exists()) {
            $slug = Str::slug($name) . '-' . $count;
            $count++;
        }

        return $slug;
    }

    /**
     * Get cache tags
     */
    protected function getCacheTags(): array
    {
        return ['tags'];
    }

    /**
     * Clear cache
     */
    protected function clearCache(): void
    {
        Cache::tags($this->getCacheTags())->flush();
    }
}
