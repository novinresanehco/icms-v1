// File: app/Core/Tag/Manager/TagManager.php
<?php

namespace App\Core\Tag\Manager;

class TagManager
{
    protected TagRepository $repository;
    protected TagValidator $validator;
    protected TagCache $cache;
    protected EventDispatcher $events;

    public function create(array $data): Tag
    {
        $this->validator->validate($data);

        DB::beginTransaction();
        try {
            $tag = $this->repository->create([
                'name' => $data['name'],
                'slug' => Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'default',
                'metadata' => $data['metadata'] ?? []
            ]);

            $this->cache->invalidate();
            $this->events->dispatch(new TagCreated($tag));
            
            DB::commit();
            return $tag;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagException("Failed to create tag: " . $e->getMessage());
        }
    }

    public function attachToContent(int $contentId, array $tagIds): void
    {
        $content = Content::findOrFail($contentId);
        
        DB::beginTransaction();
        try {
            $content->tags()->sync($tagIds);
            $this->cache->invalidateContent($contentId);
            $this->events->dispatch(new TagsAttached($content, $tagIds));
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagException("Failed to attach tags: " . $e->getMessage());
        }
    }

    public function getPopularTags(int $limit = 10): Collection
    {
        return $this->cache->remember('popular_tags', function() use ($limit) {
            return $this->repository->getPopularTags($limit);
        });
    }
}

// File: app/Core/Tag/Cache/TagCache.php
<?php

namespace App\Core\Tag\Cache;

class TagCache
{
    protected CacheManager $cache;
    protected array $tags = ['tags'];
    protected int $ttl = 3600;

    public function remember(string $key, Closure $callback)
    {
        return $this->cache->tags($this->tags)
            ->remember($this->getKey($key), $this->ttl, $callback);
    }

    public function invalidate(): void
    {
        $this->cache->tags($this->tags)->flush();
    }

    public function invalidateContent(int $contentId): void
    {
        $this->cache->tags(['content:'.$contentId, 'tags'])->flush();
    }

    protected function getKey(string $key): string
    {
        return "tags:{$key}";
    }
}

// File: app/Core/Tag/Analytics/TagAnalytics.php
<?php

namespace App\Core\Tag\Analytics;

class TagAnalytics
{
    protected TagRepository $repository;
    protected AnalyticsEngine $analytics;
    protected MetricsCollector $metrics;

    public function getTagMetrics(): array
    {
        return [
            'total_tags' => $this->repository->count(),
            'unused_tags' => $this->getUnusedTagsCount(),
            'popular_tags' => $this->getPopularTags(),
            'tag_usage_distribution' => $this->getTagUsageDistribution(),
            'recent_activity' => $this->getRecentActivity()
        ];
    }

    public function analyzeTagTrends(): array
    {
        return $this->analytics->analyze([
            'usage_trends' => $this->getUsageTrends(),
            'correlation_matrix' => $this->getTagCorrelations(),
            'content_distribution' => $this->getContentDistribution()
        ]);
    }

    protected function getTagCorrelations(): array
    {
        return $this->repository->getTags()
            ->map(function ($tag) {
                return [
                    'tag' => $tag,
                    'correlations' => $this->findCorrelatedTags($tag)
                ];
            })->toArray();
    }
}

// File: app/Core/Tag/Search/TagSearchEngine.php
<?php

namespace App\Core\Tag\Search;

class TagSearchEngine
{
    protected SearchIndexer $indexer;
    protected SearchAnalyzer $analyzer;
    protected SearchCache $cache;

    public function search(string $query, array $filters = []): SearchResult
    {
        $normalizedQuery = $this->analyzer->normalize($query);
        
        if ($cachedResult = $this->cache->get($normalizedQuery, $filters)) {
            return $cachedResult;
        }

        $searchResult = $this->indexer->search($normalizedQuery, [
            'filters' => $filters,
            'boost' => [
                'name' => 2.0,
                'description' => 1.0
            ]
        ]);

        $this->cache->put($normalizedQuery, $filters, $searchResult);
        return $searchResult;
    }

    public function suggest(string $prefix): array
    {
        return $this->cache->remember("suggest:$prefix", function() use ($prefix) {
            return $this->indexer->suggest($prefix);
        });
    }
}
