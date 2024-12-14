<?php

namespace App\Repositories;

use App\Models\Content;
use App\Repositories\Contracts\ContentRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    protected array $searchableFields = [
        'title',
        'slug',
        'content',
        'meta_title',
        'meta_description'
    ];

    protected array $filterableFields = [
        'status',
        'type',
        'category_id',
        'author_id',
        'language'
    ];

    protected array $relationships = [
        'categories' => 'sync',
        'tags' => 'sync',
        'meta' => 'update'
    ];

    public function __construct(Content $model)
    {
        parent::__construct($model);
    }

    public function findBySlug(string $slug, array $relations = []): ?Content
    {
        try {
            return Cache::remember(
                $this->getCacheKey("slug.{$slug}", $relations),
                $this->cacheTTL,
                fn() => $this->model->with($relations)->where('slug', $slug)->first()
            );
        } catch (\Exception $e) {
            Log::error('Failed to find content by slug: ' . $e->getMessage());
            return null;
        }
    }

    public function getPublished(array $filters = [], array $relations = []): Collection
    {
        try {
            $cacheKey = $this->getCacheKey('published', $relations) . '.' . md5(json_encode($filters));
            
            return Cache::remember(
                $cacheKey,
                $this->cacheTTL,
                function () use ($filters, $relations) {
                    $query = $this->model->query()
                        ->with($relations)
                        ->where('status', 'published')
                        ->where('published_at', '<=', now());
                    
                    $this->applyFilters($query, $filters);
                    
                    return $query->latest('published_at')->get();
                }
            );
        } catch (\Exception $e) {
            Log::error('Failed to get published content: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function getByType(string $type, array $relations = []): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey("type.{$type}", $relations),
                $this->cacheTTL,
                fn() => $this->model->with($relations)
                    ->where('type', $type)
                    ->latest()
                    ->get()
            );
        } catch (\Exception $e) {
            Log::error('Failed to get content by type: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function createVersion(int $contentId, array $data): bool
    {
        try {
            DB::beginTransaction();
            
            $content = $this->find($contentId);
            if (!$content) {
                throw new \Exception('Content not found');
            }
            
            $content->versions()->create([
                'content' => $data['content'],
                'meta_data' => $data['meta_data'] ?? [],
                'created_by' => auth()->id()
            ]);
            
            DB::commit();
            $this->clearModelCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create content version: ' . $e->getMessage());
            return false;
        }
    }

    public function getRelated(int $contentId, int $limit = 5): Collection
    {
        try {
            $content = $this->find($contentId, ['categories', 'tags']);
            if (!$content) {
                return new Collection();
            }

            $query = $this->model->query()
                ->where('id', '!=', $contentId)
                ->where('status', 'published')
                ->where('published_at', '<=', now());

            // Match by categories
            if ($content->categories->isNotEmpty()) {
                $query->whereHas('categories', function (Builder $q) use ($content) {
                    $q->whereIn('id', $content->categories->pluck('id'));
                });
            }

            // Match by tags
            if ($content->tags->isNotEmpty()) {
                $query->orWhereHas('tags', function (Builder $q) use ($content) {
                    $q->whereIn('id', $content->tags->pluck('id'));
                });
            }

            return $query->latest('published_at')
                ->limit($limit)
                ->get();
        } catch (\Exception $e) {
            Log::error('Failed to get related content: ' . $e->getMessage());
            return new Collection();
        }
    }
}
