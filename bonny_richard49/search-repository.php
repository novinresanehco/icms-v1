<?php

namespace App\Core\Search\Repository;

use App\Core\Search\Models\SearchIndex;
use App\Core\Search\Models\SearchLog;
use App\Core\Search\DTO\SearchData;
use App\Core\Search\Events\ContentIndexed;
use App\Core\Search\Events\ContentDeindexed;
use App\Core\Search\Events\SearchPerformed;
use App\Core\Search\Services\SearchAnalyzer;
use App\Core\Search\Services\IndexProcessor;
use App\Core\Search\Exceptions\SearchException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class SearchRepository extends BaseRepository implements SearchRepositoryInterface
{
    protected const CACHE_KEY = 'search';
    protected const CACHE_TTL = 1800; // 30 minutes

    protected SearchAnalyzer $analyzer;
    protected IndexProcessor $processor;

    public function __construct(
        CacheManagerInterface $cache,
        SearchAnalyzer $analyzer,
        IndexProcessor $processor
    ) {
        parent::__construct($cache);
        $this->analyzer = $analyzer;
        $this->processor = $processor;
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return SearchIndex::class;
    }

    public function index(SearchData $data): SearchIndex
    {
        DB::beginTransaction();
        try {
            // Process content for indexing
            $processedData = $this->processor->process($data);

            // Create or update index
            $index = $this->model->updateOrCreate(
                [
                    'model_type' => $data->modelType,
                    'model_id' => $data->modelId
                ],
                [
                    'title' => $data->title,
                    'content' => $processedData['content'],
                    'metadata' => $data->metadata,
                    'keywords' => $processedData['keywords'],
                    'weight' => $data->weight ?? 1.0
                ]
            );

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new ContentIndexed($index));

            DB::commit();
            return $index->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SearchException("Failed to index content: {$e->getMessage()}", 0, $e);
        }
    }

    public function updateIndex(int $id, SearchData $data): SearchIndex
    {
        DB::beginTransaction();
        try {
            $index = $this->findOrFail($id);
            
            // Process content for indexing
            $processedData = $this->processor->process($data);

            // Update index
            $index->update([
                'title' => $data->title,
                'content' => $processedData['content'],
                'metadata' => array_merge($index->metadata ?? [], $data->metadata ?? []),
                'keywords' => $processedData['keywords'],
                'weight' => $data->weight ?? $index->weight
            ]);

            // Clear cache
            $this->clearCache();

            DB::commit();
            return $index->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SearchException("Failed to update index: {$e->getMessage()}", 0, $e);
        }
    }

    public function removeFromIndex(string $modelType, int $modelId): bool
    {
        DB::beginTransaction();
        try {
            $index = $this->model->where('model_type', $modelType)
                                ->where('model_id', $modelId)
                                ->first();

            if ($index) {
                $index->delete();
                $this->clearCache();
                Event::dispatch(new ContentDeindexed($modelType, $modelId));
            }

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SearchException("Failed to remove from index: {$e->getMessage()}", 0, $e);
        }
    }

    public function search(string $query, array $filters = []): LengthAwarePaginator
    {
        try {
            // Analyze and prepare search query
            $analyzedQuery = $this->analyzer->analyzeQuery($query);

            // Build search query
            $searchQuery = $this->model->whereRaw(
                'MATCH(title, content, keywords) AGAINST(? IN BOOLEAN MODE)',
                [$analyzedQuery]
            );

            // Apply filters
            if (isset($filters['model_type'])) {
                $searchQuery->where('model_type', $filters['model_type']);
            }

            if (isset($filters['metadata'])) {
                foreach ($filters['metadata'] as $key => $value) {
                    $searchQuery->where("metadata->$key", $value);
                }
            }

            // Get results
            $results = $searchQuery->orderByRaw(
                'MATCH(title, content, keywords) AGAINST(? IN BOOLEAN MODE) * weight DESC',
                [$analyzedQuery]
            )->paginate($filters['per_page'] ?? 15);

            // Log search
            $this->logSearch($query, [
                'filters' => $filters,
                'total_results' => $results->total()
            ]);

            // Dispatch event
            Event::dispatch(new SearchPerformed($query, $results->total()));

            return $results;
        } catch (\Exception $e) {
            throw new SearchException("Search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getRelated(string $modelType, int $modelId, int $limit = 5): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("related:{$modelType}:{$modelId}"),
            function() use ($modelType, $modelId, $limit) {
                $source = $this->model->where('model_type', $modelType)
                                    ->where('model_id', $modelId)
                                    ->first();

                if (!$source) {
                    return collect();
                }

                return $this->model->whereRaw(
                    'MATCH(title, content, keywords) AGAINST(? IN BOOLEAN MODE)',
                    [$source->keywords]
                )
                ->where('model_type', $modelType)
                ->where('model_id', '!=', $modelId)
                ->orderByRaw(
                    'MATCH(title, content, keywords) AGAINST(? IN BOOLEAN MODE) * weight DESC',
                    [$source->keywords]
                )
                ->limit($limit)
                ->get();
            }
        );
    }

    public function getPopularSearchTerms(int $limit = 10): array
    {
        return $this->cache->remember(
            $this->getCacheKey("popular_terms:{$limit}"),
            fn() => SearchLog::select('query', DB::raw('COUNT(*) as count'))
                            ->where('created_at', '>=', now()->subDays(30))
                            ->groupBy('query')
                            ->orderBy('count', 'desc')
                            ->limit($limit)
                            ->pluck('count', 'query')
                            ->toArray()
        );
    }

    public function logSearch(string $query, array $metadata = []): bool
    {
        try {
            SearchLog::create([
                'query' => $query,
                'metadata' => $metadata,
                'user_id' => auth()->id(),
                'ip_address' => request()->ip()
            ]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function rebuildIndex(?string $modelType = null): int
    {
        DB::beginTransaction();
        try {
            // Clear existing index
            if ($modelType) {
                $this->model->where('model_type', $modelType)->delete();
            } else {
                $this->model->truncate();
            }

            // Get indexable models
            $models = $modelType 
                ? [app($modelType)]
                : config('search.indexable_models', []);

            $count = 0;
            foreach ($models as $model) {
                $items = $model::all();
                foreach ($items as $item) {
                    $this->index(new SearchData([
                        'model_type' => get_class($item),
                        'model_id' => $item->id,
                        'title' => $item->getSearchTitle(),
                        'content' => $item->getSearchContent(),
                        'metadata' => $item->getSearchMetadata(),
                        'weight' => $item->getSearchWeight()
                    ]));
                    $count++;
                }
            }

            DB::commit();
            return $count;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SearchException("Failed to rebuild index: {$e->getMessage()}", 0, $e);
        }
    }

    public function getIndexingStats(): array
    {
        return $this->cache->remember(
            $this->getCacheKey('indexing_stats'),
            function() {
                return [
                    'total_indexed' => $this->model->count(),
                    'by_type' => $this->model->select('model_type', DB::raw('count(*) as count'))
                        ->groupBy('model_type')
                        ->pluck('count', 'model_type')
                        ->toArray(),
                    'last_indexed' => $this->model->max('updated_at'),
                    'average_weight' => $this->model->avg('weight')
                ];
            }
        );
    }

    public function suggestTerms(string $query, int $limit = 5): array
    {
        return $this->cache->remember(
            $this->getCacheKey("suggestions:{$query}:{$limit}"),
            fn() => $this->analyzer->suggestTerms($query, $limit)
        );
    }

    public function getSearchAnalytics(array $filters = []): array
    {
        return $this->cache->remember(
            $this->getCacheKey('analytics:' . md5(serialize($filters))),
            function() use ($filters) {
                $query = SearchLog::query();

                if (isset($filters['from_date'])) {
                    $query->where('created_at', '>=', $filters['from_date']);
                }

                if (isset($filters['to_date'])) {
                    $query->where('created_at', '<=', $filters['to_date']);
                }

                return [
                    'total_searches' => $query->count(),
                    'unique_queries' => $query->distinct('query')->count(),
                    'queries_by_day' => $query->select(
                        DB::raw('DATE(created_at) as date'),
                        DB::raw('COUNT(*) as count')
                    )
                        ->groupBy('date')
                        ->pluck('count', 'date')
                        ->toArray(),
                    'popular_terms' => $this->getPopularSearchTerms(),
                    'zero_results_queries' => $query->whereJsonContains('metadata->total_results', 0)
                        ->select('query', DB::raw('COUNT(*) as count'))
                        ->groupBy('query')
                        ->orderBy('count', 'desc')
                        ->limit(10)
                        ->pluck('count', 'query')
                        ->toArray()
                ];
            }
        );
    }
}
