<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\SearchManagerInterface;

class SearchManager implements SearchManagerInterface 
{
    private SecurityManager $security;
    private IndexManager $index;
    private QueryBuilder $builder;
    private CacheManager $cache;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        IndexManager $index,
        QueryBuilder $builder,
        CacheManager $cache,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->index = $index;
        $this->builder = $builder;
        $this->cache = $cache;
        $this->metrics = $metrics;
    }

    public function search(SearchRequest $request, SecurityContext $context): SearchResult
    {
        return $this->security->executeCriticalOperation(
            new SearchOperation(
                $request,
                $this->builder,
                $this->cache,
                $this->metrics
            ),
            $context
        );
    }

    public function index(Indexable $entity, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new IndexOperation(
                $entity,
                $this->index,
                $this->cache
            ),
            $context
        );
    }

    public function reindex(string $type, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new ReindexOperation(
                $type,
                $this->index,
                $this->cache
            ),
            $context
        );
    }
}

class SearchOperation implements CriticalOperation
{
    private SearchRequest $request;
    private QueryBuilder $builder;
    private CacheManager $cache;
    private MetricsCollector $metrics;

    public function execute(): SearchResult
    {
        $startTime = microtime(true);
        $cacheKey = $this->getCacheKey();

        try {
            if ($cached = $this->cache->get($cacheKey)) {
                $this->metrics->recordCacheHit($cacheKey);
                return $cached;
            }

            $query = $this->builder->build($this->request);
            $results = DB::select($query->toSql(), $query->getBindings());
            
            $searchResult = new SearchResult($results);
            
            $this->cache->set(
                $cacheKey,
                $searchResult,
                config('search.cache.ttl')
            );

            $this->metrics->recordSearch(
                $this->request,
                count($results),
                microtime(true) - $startTime
            );

            return $searchResult;

        } catch (\Exception $e) {
            $this->metrics->recordError($this->request, $e);
            throw $e;
        }
    }

    private function getCacheKey(): string
    {
        return 'search:' . md5(serialize($this->request));
    }

    public function getValidationRules(): array
    {
        return [
            'query' => 'required|string|max:1000',
            'filters' => 'array',
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100'
        ];
    }

    public function getData(): array
    {
        return $this->request->toArray();
    }

    public function getRequiredPermissions(): array
    {
        return ['search:execute'];
    }
}

class IndexManager
{
    private array $indexers = [];
    
    public function register(string $type, EntityIndexer $indexer): void
    {
        $this->indexers[$type] = $indexer;
    }

    public function index(Indexable $entity): void
    {
        $indexer = $this->getIndexer($entity);
        $document = $indexer->toDocument($entity);
        
        DB::transaction(function() use ($document, $entity) {
            $this->deleteExisting($entity);
            $this->insertDocument($document);
            $this->updateRelated($entity);
        });
    }

    public function reindex(string $type): void
    {
        $indexer = $this->indexers[$type];
        $entities = $indexer->getEntities();
        
        DB::transaction(function() use ($entities, $indexer) {
            $this->clearIndex($type);
            
            foreach ($entities as $entity) {
                $document = $indexer->toDocument($entity);
                $this->insertDocument($document);
            }
        });
    }

    private function getIndexer(Indexable $entity): EntityIndexer
    {
        $type = get_class($entity);
        
        if (!isset($this->indexers[$type])) {
            throw new \RuntimeException("No indexer registered for {$type}");
        }
        
        return $this->indexers[$type];
    }

    private function deleteExisting(Indexable $entity): void
    {
        DB::table('search_index')
            ->where('entity_type', get_class($entity))
            ->where('entity_id', $entity->getId())
            ->delete();
    }

    private function insertDocument(SearchDocument $document): void
    {
        DB::table('search_index')->insert([
            'entity_type' => $document->getType(),
            'entity_id' => $document->getId(),
            'title' => $document->getTitle(),
            'content' => $document->getContent(),
            'metadata' => json_encode($document->getMetadata()),
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }

    private function updateRelated(Indexable $entity): void
    {
        foreach ($entity->getRelatedEntities() as $related) {
            $this->index($related);
        }
    }

    private function clearIndex(string $type): void
    {
        DB::table('search_index')
            ->where('entity_type', $type)
            ->delete();
    }
}

class QueryBuilder
{
    private array $config;

    public function build(SearchRequest $request): Query
    {
        $query = DB::table('search_index')
            ->select($this->getFields())
            ->where(function($q) use ($request) {
                $this->applySearchConditions($q, $request);
            });

        $this->applyFilters($query, $request->getFilters());
        $this->applySorting($query, $request->getSort());
        $this->applyPagination($query, $request->getPage(), $request->getPerPage());

        return $query;
    }

    private function getFields(): array
    {
        return [
            'id',
            'entity_type',
            'entity_id',
            'title',
            'content',
            'metadata',
            'relevance' => $this->getRelevanceScore()
        ];
    }

    private function getRelevanceScore(): string
    {
        return DB::raw("
            (MATCH(title) AGAINST(? IN BOOLEAN MODE) * 2) +
            (MATCH(content) AGAINST(? IN BOOLEAN MODE))
            AS relevance
        ");
    }

    private function applySearchConditions($query, SearchRequest $request): void
    {
        $terms = $this->parseSearchTerms($request->getQuery());
        
        foreach ($terms as $term) {
            $query->where(function($q) use ($term) {
                $q->whereRaw("MATCH(title) AGAINST(? IN BOOLEAN MODE)", [$term])
                  ->orWhereRaw("MATCH(content) AGAINST(? IN BOOLEAN MODE)", [$term]);
            });
        }
    }

    private function parseSearchTerms(string $query): array
    {
        return array_filter(
            array_map('trim', explode(' ', $query)),
            function($term) {
                return strlen($term) >= $this->config['min_term_length'];
            }
        );
    }
}
