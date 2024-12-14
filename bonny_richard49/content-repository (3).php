<?php

namespace App\Core\Repositories;

use App\Core\Interfaces\ContentRepositoryInterface;
use App\Core\Models\Content;
use App\Core\Security\SecurityManager;
use App\Core\System\CacheService;
use Psr\Log\LoggerInterface;
use Illuminate\Database\Eloquent\Builder;
use App\Core\Exceptions\ContentException;

class ContentRepository implements ContentRepositoryInterface
{
    private SecurityManager $security;
    private CacheService $cache;
    private LoggerInterface $logger;
    private array $config;

    private const CACHE_TTL = 3600;
    private const BATCH_SIZE = 100;
    private const MAX_RESULTS = 1000;

    public function __construct(
        SecurityManager $security,
        CacheService $cache,
        LoggerInterface $logger
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->config = config('content');
    }

    public function find(int $id, array $relations = []): ?Content
    {
        try {
            return $this->cache->remember(
                $this->getCacheKey('content', $id),
                function() use ($id, $relations) {
                    $query = Content::query();

                    if (!empty($relations)) {
                        $query->with($relations);
                    }

                    return $query->find($id);
                },
                self::CACHE_TTL
            );
        } catch (\Exception $e) {
            $this->handleError('Failed to find content', $e);
        }
    }

    public function create(array $data): Content
    {
        try {
            DB::beginTransaction();

            $content = new Content($data);
            $content->save();

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            DB::commit();

            $this->cache->forget($this->getCacheKey('content', $content->id));
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('Failed to create content', $e);
        }
    }

    public function update(int $id, array $data): Content
    {
        try {
            DB::beginTransaction();

            $content = $this->find($id);
            if (!$content) {
                throw new ContentException("Content not found: {$id}");
            }

            $content->update($data);

            if (isset($data['categories'])) {
                $content->categories()->sync($data['categories']);
            }

            if (isset($data['tags'])) {
                $content->tags()->sync($data['tags']);
            }

            DB::commit();

            $this->cache->forget($this->getCacheKey('content', $id));
            return $content;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('Failed to update content', $e);
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();

            $content = $this->find($id);
            if (!$content) {
                throw new ContentException("Content not found: {$id}");
            }

            $content->categories()->detach();
            $content->tags()->detach();
            $content->delete();

            DB::commit();

            $this->cache->forget($this->getCacheKey('content', $id));
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('Failed to delete content', $e);
        }
    }

    public function findBySlug(string $slug): ?Content
    {
        try {
            return $this->cache->remember(
                $this->getCacheKey('slug', $slug),
                function() use ($slug) {
                    return Content::where('slug', $slug)->first();
                },
                self::CACHE_TTL
            );
        } catch (\Exception $e) {
            $this->handleError('Failed to find content by slug', $e);
        }
    }

    public function search(array $criteria, array $relations = []): array
    {
        try {
            $query = Content::query();

            if (!empty($relations)) {
                $query->with($relations);
            }

            $this->applySearchCriteria($query, $criteria);

            return $query->take(self::MAX_RESULTS)->get()->all();

        } catch (\Exception $e) {
            $this->handleError('Failed to search content', $e);
        }
    }

    public function findByCategory(int $categoryId, array $relations = []): array
    {
        try {
            return $this->cache->remember(
                $this->getCacheKey('category', $categoryId),
                function() use ($categoryId, $relations) {
                    $query = Content::whereHas('categories', function($query) use ($categoryId) {
                        $query->where('id', $categoryId);
                    });

                    if (!empty($relations)) {
                        $query->with($relations);
                    }

                    return $query->get()->all();
                },
                self::CACHE_TTL
            );
        } catch (\Exception $e) {
            $this->handleError('Failed to find content by category', $e);
        }
    }

    public function findByTag(int $tagId, array $relations = []): array
    {
        try {
            return $this->cache->remember(
                $this->getCacheKey('tag', $tagId),
                function() use ($tagId, $relations) {
                    $query = Content::whereHas('tags', function($query) use ($tagId) {
                        $query->where('id', $tagId);
                    });

                    if (!empty($relations)) {
                        $query->with($relations);
                    }

                    return $query->get()->all();
                },
                self::CACHE_TTL
            );
        } catch (\Exception $e) {
            $this->handleError('Failed to find content by tag', $e);
        }
    }

    private function applySearchCriteria(Builder $query, array $criteria): void
    {
        if (isset($criteria['title'])) {
            $query->where('title', 'like', "%{$criteria['title']}%");
        }

        if (isset($criteria['status'])) {
            $query->where('status', $criteria['status']);
        }

        if (isset($criteria['type'])) {
            $query->where('type', $criteria['type']);
        }

        if (isset($criteria['author_id'])) {
            $query->where('author_id', $criteria['author_id']);
        }

        if (isset($criteria['created_after'])) {
            $query->where('created_at', '>=', $criteria['created_after']);
        }

        if (isset($criteria['created_before'])) {
            $query->where('created_at', '<=', $criteria['created_before']);
        }

        if (isset($criteria['categories'])) {
            $query->whereHas('categories', function($query) use ($criteria) {
                $query->whereIn('id', (array) $criteria['categories']);
            });
        }

        if (isset($criteria['tags'])) {
            $query->whereHas('tags', function($query) use ($criteria) {
                $query->whereIn('id', (array) $criteria['tags']);
            });
        }

        if (isset($criteria['order_by'])) {
            $direction = $criteria['order_direction'] ?? 'asc';
            $query->orderBy($criteria['order_by'], $direction);
        }
    }

    private function getCacheKey(string $type, $identifier): string
    {
        return "content:{$type}:{$identifier}";
    }

    private function handleError(string $message, \Exception $e): void
    {
        $this->logger->error($message, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        throw new ContentException($message, 0, $e);
    }
}
