<?php

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\ContentRepositoryInterface;
use App\Core\Models\Content;
use App\Core\Exceptions\ContentRepositoryException;
use Illuminate\Database\Eloquent\{Model, Collection, Builder};
use Illuminate\Support\{Carbon, Str};
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\{Cache, DB};

class ContentRepository implements ContentRepositoryInterface
{
    protected Content $model;
    protected const CACHE_PREFIX = 'content:';
    protected const CACHE_TTL = 3600; // 1 hour

    public function __construct(Content $model)
    {
        $this->model = $model;
    }

    /**
     * Create new content
     *
     * @param array $data
     * @return Model
     * @throws ContentRepositoryException
     */
    public function create(array $data): Model
    {
        try {
            DB::beginTransaction();

            $content = $this->model->create([
                'title' => $data['title'],
                'slug' => $data['slug'] ?? Str::slug($data['title']),
                'content' => $data['content'],
                'type' => $data['type'] ?? 'page',
                'status' => $data['status'] ?? 'draft',
                'author_id' => $data['author_id'],
                'meta_description' => $data['meta_description'] ?? null,
                'meta_keywords' => $data['meta_keywords'] ?? null,
                'published_at' => $data['published_at'] ?? null,
                'template' => $data['template'] ?? 'default',
                'language' => $data['language'] ?? config('app.locale')
            ]);

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            DB::commit();
            $this->clearCache($content);

            return $content;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentRepositoryException(
                "Failed to create content: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Update existing content
     *
     * @param int $id
     * @param array $data
     * @return Model
     * @throws ContentRepositoryException|ModelNotFoundException
     */
    public function update(int $id, array $data): Model
    {
        try {
            DB::beginTransaction();

            $content = $this->model->findOrFail($id);
            
            $content->update([
                'title' => $data['title'] ?? $content->title,
                'slug' => $data['slug'] ?? $content->slug,
                'content' => $data['content'] ?? $content->content,
                'status' => $data['status'] ?? $content->status,
                'meta_description' => $data['meta_description'] ?? $content->meta_description,
                'meta_keywords' => $data['meta_keywords'] ?? $content->meta_keywords,
                'published_at' => $data['published_at'] ?? $content->published_at,
                'template' => $data['template'] ?? $content->template,
                'language' => $data['language'] ?? $content->language
            ]);

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            DB::commit();
            $this->clearCache($content);

            return $content;
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentRepositoryException(
                "Failed to update content: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get content by ID with caching
     *
     * @param int $id
     * @return Model
     * @throws ModelNotFoundException
     */
    public function findById(int $id): Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . $id,
            self::CACHE_TTL,
            fn () => $this->model->findOrFail($id)
        );
    }

    /**
     * Get content by slug with caching
     *
     * @param string $slug
     * @return Model
     * @throws ModelNotFoundException
     */
    public function findBySlug(string $slug): Model
    {
        return Cache::remember(
            self::CACHE_PREFIX . "slug:{$slug}",
            self::CACHE_TTL,
            fn () => $this->model->where('slug', $slug)->firstOrFail()
        );
    }

    /**
     * Get published content with pagination
     *
     * @param int $perPage
     * @return Collection
     */
    public function getPublished(int $perPage = 15): Collection
    {
        return $this->model->newQuery()
            ->where('status', 'published')
            ->where('published_at', '<=', Carbon::now())
            ->orderBy('published_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Search content by criteria
     *
     * @param array $criteria
     * @return Collection
     */
    public function search(array $criteria): Collection
    {
        $query = $this->model->newQuery();

        if (isset($criteria['term'])) {
            $query->where(function (Builder $q) use ($criteria) {
                $q->where('title', 'like', "%{$criteria['term']}%")
                  ->orWhere('content', 'like', "%{$criteria['term']}%");
            });
        }

        if (isset($criteria['type'])) {
            $query->where('type', $criteria['type']);
        }

        if (isset($criteria['status'])) {
            $query->where('status', $criteria['status']);
        }

        if (isset($criteria['category_id'])) {
            $query->whereHas('categories', function (Builder $q) use ($criteria) {
                $q->where('categories.id', $criteria['category_id']);
            });
        }

        if (isset($criteria['tag_id'])) {
            $query->whereHas('tags', function (Builder $q) use ($criteria) {
                $q->where('tags.id', $criteria['tag_id']);
            });
        }

        if (isset($criteria['language'])) {
            $query->where('language', $criteria['language']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($criteria['per_page'] ?? 15);
    }

    /**
     * Delete content by ID
     *
     * @param int $id
     * @return bool
     * @throws ContentRepositoryException
     */
    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $content = $this->model->findOrFail($id);
            $content->categories()->detach();
            $content->tags()->detach();
            $deleted = $content->delete();

            DB::commit();
            $this->clearCache($content);

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new ContentRepositoryException(
                "Failed to delete content: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Clear content cache
     *
     * @param Model $content
     * @return void
     */
    protected function clearCache(Model $content): void
    {
        Cache::forget(self::CACHE_PREFIX . $content->id);
        Cache::forget(self::CACHE_PREFIX . "slug:{$content->slug}");
    }
}
