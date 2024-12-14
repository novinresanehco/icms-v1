<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\ContentRepositoryInterface;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Exceptions\ContentException;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    /**
     * Cache TTL in seconds
     */
    protected const CACHE_TTL = 3600;

    /**
     * @param Content $model
     */
    public function __construct(Content $model)
    {
        parent::__construct($model);
    }

    /**
     * {@inheritDoc}
     */
    public function findBySlug(string $slug): ?Content
    {
        return Cache::remember("content:slug:{$slug}", self::CACHE_TTL, function () use ($slug) {
            return $this->model->with(['author', 'category', 'tags'])
                ->where('slug', $slug)
                ->first();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getPublished(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with(['author', 'category', 'tags'])
            ->where('status', 'published')
            ->where('published_at', '<=', now());

        // Apply filters
        if (!empty($filters['category'])) {
            $query->whereHas('category', function ($q) use ($filters) {
                $q->where('slug', $filters['category']);
            });
        }

        if (!empty($filters['tag'])) {
            $query->whereHas('tags', function ($q) use ($filters) {
                $q->where('slug', $filters['tag']);
            });
        }

        if (!empty($filters['author'])) {
            $query->where('author_id', $filters['author']);
        }

        return $query->orderBy('published_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function getByType(string $type, array $filters = []): Collection
    {
        return Cache::remember(
            "content:type:{$type}:" . md5(serialize($filters)),
            self::CACHE_TTL,
            function () use ($type, $filters) {
                $query = $this->model->with(['author', 'category', 'tags'])
                    ->where('type', $type);

                foreach ($filters as $field => $value) {
                    $query->where($field, $value);
                }

                return $query->get();
            }
        );
    }

    /**
     * {@inheritDoc}
     */
    public function publish(int $contentId, array $publishConfig = []): Content
    {
        try {
            DB::beginTransaction();

            $content = $this->findById($contentId);
            if (!$content) {
                throw new ContentException("Content not found with ID: {$contentId}");
            }

            $content->update([
                'status' => 'published',
                'published_at' => $publishConfig['published_at'] ?? now(),
                'publisher_id' => auth()->id(),
                'publish_config' => json_encode($publishConfig)
            ]);

            // Create a published version
            $this->createVersion($contentId, [
                'type' => 'publish',
                'data' => $content->toArray()
            ]);

            DB::commit();

            $this->clearContentCache($content);

            return $content->fresh();
        } catch (QueryException $e) {
            DB::rollBack();
            throw new ContentException("Error publishing content: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function unpublish(int $contentId): Content
    {
        try {
            DB::beginTransaction();

            $content = $this->findById($contentId);
            if (!$content) {
                throw new ContentException("Content not found with ID: {$contentId}");
            }

            $content->update([
                'status' => 'draft',
                'published_at' => null
            ]);

            // Create an unpublish version
            $this->createVersion($contentId, [
                'type' => 'unpublish',
                'data' => $content->toArray()
            ]);

            DB::commit();

            $this->clearContentCache($content);

            return $content->fresh();
        } catch (QueryException $e) {
            DB::rollBack();
            throw new ContentException("Error unpublishing content: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getVersions(int $contentId): Collection
    {
        return ContentVersion::where('content_id', $contentId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function createVersion(int $contentId, array $data): Content
    {
        try {
            $content = $this->findById($contentId);
            if (!$content) {
                throw new ContentException("Content not found with ID: {$contentId}");
            }

            ContentVersion::create([
                'content_id' => $contentId,
                'user_id' => auth()->id(),
                'type' => $data['type'],
                'data' => json_encode($data['data']),
                'metadata' => json_encode($data['metadata'] ?? [])
            ]);

            return $content->fresh();
        } catch (QueryException $e) {
            throw new ContentException("Error creating content version: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function restoreVersion(int $contentId, int $versionId): Content
    {
        try {
            DB::beginTransaction();

            $content = $this->findById($contentId);
            if (!$content) {
                throw new ContentException("Content not found with ID: {$contentId}");
            }

            $version = ContentVersion::findOrFail($versionId);
            $versionData = json_decode($version->data, true);

            // Update content with version data
            $content->update(array_intersect_key(
                $versionData,
                array_flip(['title', 'content', 'meta_description', 'meta_keywords'])
            ));

            // Create a restore version
            $this->createVersion($contentId, [
                'type' => 'restore',
                'data' => $content->toArray(),
                'metadata' => ['restored_from' => $versionId]
            ]);

            DB::commit();

            $this->clearContentCache($content);

            return $content->fresh();
        } catch (QueryException $e) {
            DB::rollBack();
            throw new ContentException("Error restoring content version: {$e->getMessage()}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getRelated(int $contentId, int $limit = 5): Collection
    {
        return Cache::remember("content:related:{$contentId}", self::CACHE_TTL, function () use ($contentId, $limit) {
            $content = $this->findById($contentId);
            if (!$content) {
                return collect();
            }

            return $this->model->with(['author', 'category', 'tags'])
                ->where('id', '!=', $contentId)
                ->where('status', 'published')
                ->where('type', $content->type)
                ->whereHas('tags', function ($query) use ($content) {
                    $query->whereIn('id', $content->tags->pluck('id'));
                })
                ->orderBy('published_at', 'desc')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Clear content cache
     *
     * @param Content $content
     * @return void
     */
    protected function clearContentCache(Content $content): void
    {
        Cache::forget("content:slug:{$content->slug}");
        Cache::tags(['content', "content:type:{$content->type}"])->flush();
    }
}
