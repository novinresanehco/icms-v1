<?php

namespace App\Core\Content\Repository;

use App\Core\Content\Models\Content;
use App\Core\Content\DTO\ContentData;
use App\Core\Content\Events\ContentCreated;
use App\Core\Content\Events\ContentUpdated;
use App\Core\Content\Events\ContentDeleted;
use App\Core\Content\Events\ContentStatusChanged;
use App\Core\Content\Exceptions\ContentNotFoundException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use DateTime;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    protected const CACHE_KEY = 'content';
    protected const CACHE_TTL = 3600; // 1 hour

    public function __construct(CacheManagerInterface $cache)
    {
        parent::__construct($cache);
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return Content::class;
    }

    public function create(ContentData $data): Content
    {
        $errors = $data->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Invalid content data: ' . json_encode($errors));
        }

        DB::beginTransaction();
        try {
            // Create content
            $content = $this->model->create($data->jsonSerialize());

            // Sync relationships
            if (!empty($data->tags)) {
                $content->tags()->sync($data->tags);
            }
            if (!empty($data->media)) {
                $content->media()->sync($data->media);
            }
            if (!empty($data->attributes)) {
                $content->attributes()->sync($data->attributes);
            }

            DB::commit();

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new ContentCreated($content));

            return $content->fresh(['category', 'author', 'tags', 'media']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, ContentData $data): Content
    {
        $errors = $data->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Invalid content data: ' . json_encode($errors));
        }

        DB::beginTransaction();
        try {
            $content = $this->findOrFail($id);
            
            // Update content
            $content->update($data->jsonSerialize());

            // Sync relationships
            if (isset($data->tags)) {
                $content->tags()->sync($data->tags);
            }
            if (isset($data->media)) {
                $content->media()->sync($data->media);
            }
            if (isset($data->attributes)) {
                $content->attributes()->sync($data->attributes);
            }

            DB::commit();

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new ContentUpdated($content));

            return $content->fresh(['category', 'author', 'tags', 'media']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->cache->remember(
            $this->getCacheKey("slug:{$slug}"),
            fn() => $this->model->where('slug', $slug)
                               ->with(['category', 'author', 'tags', 'media'])
                               ->first()
        );
    }

    public function findPublished(int $id): ?Content
    {
        return $this->cache->remember(
            $this->getCacheKey("published:{$id}"),
            fn() => $this->model->where('id', $id)
                               ->where('status', 'published')
                               ->whereNotNull('published_at')
                               ->with(['category', 'author', 'tags', 'media'])
                               ->first()
        );
    }

    public function getPaginatedPublished(int $page = 1, int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->where('status', 'published')
                            ->whereNotNull('published_at')
                            ->with(['category', 'author', 'tags']);

        // Apply filters
        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (!empty($filters['tag_id'])) {
            $query->whereHas('tags', function($q) use ($filters) {
                $q->where('tags.id', $filters['tag_id']);
            });
        }
        if (!empty($filters['author_id'])) {
            $query->where('author_id', $filters['author_id']);
        }

        // Apply sorting
        $sortField = $filters['sort_by'] ?? 'published_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';
        $query->orderBy($sortField, $sortDir);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function findByCategory(int $categoryId, array $options = []): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("category:{$categoryId}"),
            fn() => $this->model->where('category_id', $categoryId)
                               ->where('status', 'published')
                               ->with(['category', 'author', 'tags'])
                               ->get()
        );
    }

    public function findByTags(array $tagIds, array $options = []): Collection
    {
        $cacheKey = $this->getCacheKey("tags:" . implode(',', $tagIds));
        
        return $this->cache->remember(
            $cacheKey,
            fn() => $this->model->whereHas('tags', function($query) use ($tagIds) {
                $query->whereIn('tags.id', $tagIds);
            })
            ->where('status', 'published')
            ->with(['category', 'author', 'tags'])
            ->get()
        );
    }

    public function search(string $query, array $options = []): Collection
    {
        // Search is not cached as it's dynamic
        return $this->model->where(function($q) use ($query) {
            $q->where('title', 'LIKE', "%{$query}%")
              ->orWhere('content', 'LIKE', "%{$query}%")
              ->orWhere('excerpt', 'LIKE', "%{$query}%");
        })
        ->where('status', 'published')
        ->with(['category', 'author', 'tags'])
        ->get();
    }

    public function getFeatured(int $limit = 5): Collection
    {
        return $this->cache->remember(