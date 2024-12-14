<?php

namespace App\Core\Repository;

use App\Models\Content;
use App\Core\Events\ContentEvents;
use App\Core\Exceptions\ContentRepositoryException;

class ContentRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Content::class;
    }

    /**
     * Create content
     */
    public function createContent(array $data): Content
    {
        try {
            DB::beginTransaction();

            // Generate slug if not provided
            if (!isset($data['slug'])) {
                $data['slug'] = $this->generateUniqueSlug($data['title']);
            }

            $content = $this->create([
                'title' => $data['title'],
                'slug' => $data['slug'],
                'content' => $data['content'],
                'excerpt' => $data['excerpt'] ?? null,
                'type' => $data['type'] ?? 'post',
                'status' => $data['status'] ?? 'draft',
                'template' => $data['template'] ?? 'default',
                'author_id' => $data['author_id'] ?? auth()->id(),
                'category_id' => $data['category_id'] ?? null,
                'featured_image' => $data['featured_image'] ?? null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'published_at' => $data['published_at'] ?? null,
                'created_by' => auth()->id()
            ]);

            // Handle relationships
            if (!empty($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            if (!empty($data['metadata'])) {
                foreach ($data['metadata'] as $key => $value) {
                    $content->metadata()->create([
                        'key' => $key,
                        'value' => $value
                    ]);
                }
            }

            DB::commit();
            event(new ContentEvents\ContentCreated($content));

            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentRepositoryException(
                "Failed to create content: {$e->getMessage()}"
            );
        }
    }

    /**
     * Update content status
     */
    public function updateStatus(int $contentId, string $status): Content
    {
        try {
            $content = $this->find($contentId);
            if (!$content) {
                throw new ContentRepositoryException("Content not found with ID: {$contentId}");
            }

            $oldStatus = $content->status;
            $updates = ['status' => $status];

            if ($status === 'published' && $oldStatus !== 'published') {
                $updates['published_at'] = now();
            }

            $content->update($updates);
            $this->clearCache();

            event(new ContentEvents\ContentStatusChanged($content, $oldStatus));
            return $content;

        } catch (\Exception $e) {
            throw new ContentRepositoryException(
                "Failed to update content status: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get published content
     */
    public function getPublishedContent(array $options = []): Collection
    {
        $query = $this->model
            ->where('status', 'published')
            ->where('published_at', '<=', now());

        if (isset($options['type'])) {
            $query->where('type', $options['type']);
        }

        if (isset($options['category_id'])) {
            $query->where('category_id', $options['category_id']);
        }

        if (isset($options['tag'])) {
            $query->whereHas('tags', function($q) use ($options) {
                $q->where('slug', $options['tag']);
            });
        }

        if (isset($options['author_id'])) {
            $query->where('author_id', $options['author_id']);
        }

        $query->orderBy($options['order_by'] ?? 'published_at', $options['order'] ?? 'desc');

        if (isset($options['limit'])) {
            $query->limit($options['limit']);
        }

        return $query->get();
    }

    /**
     * Get content by slug
     */
    public function findBySlug(string $slug): ?Content
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("slug.{$slug}"),
            $this->cacheTime,
            fn() => $this->model
                ->where('slug', $slug)
                ->with(['category', 'tags', 'metadata'])
                ->first()
        );
    }

    /**
     * Search content
     */
    public function searchContent(string $query, array $options = []): Collection
    {
        $searchQuery = $this->model->newQuery();

        // Full text search on title and content
        $searchQuery->whereRaw(
            "MATCH(title, content) AGAINST(? IN BOOLEAN MODE)",
            [$this->prepareSearchQuery($query)]
        );

        if (isset($options['type'])) {
            $searchQuery->where('type', $options['type']);
        }

        if (isset($options['status'])) {
            $searchQuery->where('status', $options['status']);
        }

        if (isset($options['from_date'])) {
            $searchQuery->where('created_at', '>=', $options['from_date']);
        }

        return $searchQuery->get();
    }

    /**
     * Generate unique slug
     */
    protected function generateUniqueSlug(string $title): string
    {
        $slug = Str::slug($title);
        $count = 2;

        while ($this->model->where('slug', $slug)->exists()) {
            $slug = Str::slug($title) . '-' . $count;
            $count++;
        }

        return $slug;
    }

    /**
     * Prepare search query
     */
    protected function prepareSearchQuery(string $query): string
    {
        $terms = explode(' ', $query);
        return implode(' ', array_map(fn($term) => '+' . $term . '*', $terms));
    }
}
