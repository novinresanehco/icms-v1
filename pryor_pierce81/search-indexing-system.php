<?php

namespace App\Core\Search;

class SearchManager implements SearchManagerInterface 
{
    private SecurityManager $security;
    private Repository $repository;
    private CacheManager $cache;
    private IndexingService $indexer;
    private ValidationService $validator;
    private array $config;

    public function index(Indexable $entity): IndexResult 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'search.index',
                'entity_type' => get_class($entity),
                'entity_id' => $entity->getId()
            ]);

            $indexData = $this->prepareIndexData($entity);
            $this->validator->validateIndexData($indexData);

            $index = $this->repository->createIndex([
                'entity_type' => get_class($entity),
                'entity_id' => $entity->getId(),
                'content' => $indexData['content'],
                'metadata' => $indexData['metadata'],
                'keywords' => $this->extractKeywords($indexData['content']),
                'checksum' => $this->generateChecksum($indexData),
                'indexed_at' => now()
            ]);

            $this->cache->tags(['search'])->put(
                $this->getIndexCacheKey($entity),
                $index,
                config('cms.cache.ttl')
            );

            DB::commit();
            return new IndexResult($index);

        } catch (\Exception $e) {
            DB::rollBack();
            throw new IndexingException('Failed to index entity', 0, $e);
        }
    }

    public function search(array $criteria): SearchResults 
    {
        try {
            $validated = $this->validator->validate($criteria, [
                'query' => 'required|string|min:2',
                'type' => 'string',
                'filters' => 'array',
                'sort' => 'string|in:relevance,date',
                'limit' => 'integer|min:1|max:100',
                'offset' => 'integer|min:0'
            ]);

            $cacheKey = $this->getSearchCacheKey($validated);
            
            return $this->cache->tags(['search'])->remember(
                $cacheKey,
                config('cms.cache.ttl'),
                fn() => $this->executeSearch($validated)
            );

        } catch (\Exception $e) {
            throw new SearchException('Search operation failed', 0, $e);
        }
    }

    public function reindex(string $type = null): void 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'search.reindex',
                'type' => $type
            ]);

            $entities = $type 
                ? $this->repository->getEntitiesByType($type)
                : $this->repository->getAllEntities();

            foreach ($entities as $entity) {
                $this->index($entity);
            }

            $this->cache->tags(['search'])->flush();
            
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw new IndexingException('Reindexing failed', 0, $e);
        }
    }

    public function optimize(): void 
    {
        try {
            $this->security->validateCriticalOperation([
                'action' => 'search.optimize'
            ]);

            $this->repository->optimizeIndexes();
            $this->cache->tags(['search'])->flush();

        } catch (\Exception $e) {
            throw new SearchException('Index optimization failed', 0, $e);
        }
    }

    private function prepareIndexData(Indexable $entity): array 
    {
        return [
            'content' => $this->indexer->extractContent($entity),
            'metadata' => $this->indexer->extractMetadata($entity)
        ];
    }

    private function executeSearch(array $criteria): SearchResults 
    {
        $query = $this->buildSearchQuery($criteria);
        $results = $this->repository->search($query);
        
        return new SearchResults(
            $results,
            $this->calculateTotalResults($query),
            $criteria
        );
    }

    private function buildSearchQuery(array $criteria): SearchQuery 
    {
        $query = new SearchQuery($criteria['query']);

        if (isset($criteria['type'])) {
            $query->filterByType($criteria['type']);
        }

        if (isset($criteria['filters'])) {
            foreach ($criteria['filters'] as $field => $value) {
                $query->addFilter($field, $value);
            }
        }

        $query->setSort($criteria['sort'] ?? 'relevance');
        $query->setLimit($criteria['limit'] ?? 10);
        $query->setOffset($criteria['offset'] ?? 0);

        return $query;
    }

    private function extractKeywords(string $content): array 
    {
        $words = str_word_count(strtolower($content), 1);
        $keywords = array_diff($words, $this->config['stopwords'] ?? []);
        
        return array_slice(
            array_unique($keywords),
            0,
            $this->config['max_keywords'] ?? 100
        );
    }

    private function generateChecksum(array $data): string 
    {
        return hash('sha256', json_encode($data));
    }

    private function getIndexCacheKey(Indexable $entity): string 
    {
        return sprintf(
            'index.%s.%s',
            get_class($entity),
            $entity->getId()
        );
    }

    private function getSearchCacheKey(array $criteria): string 
    {
        return 'search.' . hash('sha256', json_encode($criteria));
    }

    private function calculateTotalResults(SearchQuery $query): int 
    {
        return $this->repository->countResults($query);
    }
}
