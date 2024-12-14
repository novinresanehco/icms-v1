<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\{DB, Cache};

class SearchManager 
{
    private SecurityManager $security;
    private SearchIndexer $indexer;
    private SearchCache $cache;
    private QueryBuilder $builder;
    private ResultProcessor $processor;

    public function __construct(
        SecurityManager $security,
        SearchIndexer $indexer,
        SearchCache $cache,
        QueryBuilder $builder,
        ResultProcessor $processor
    ) {
        $this->security = $security;
        $this->indexer = $indexer;
        $this->cache = $cache;
        $this->builder = $builder;
        $this->processor = $processor;
    }

    public function search(SearchRequest $request): SearchResult
    {
        return $this->security->protectedExecute(function() use ($request) {
            $this->validateRequest($request);
            
            $cacheKey = $this->cache->generateKey($request);
            
            return $this->cache->remember($cacheKey, function() use ($request) {
                $query = $this->builder->buildQuery($request);
                $results = $this->executeQuery($query);
                return $this->processor->process($results, $request);
            });
        });
    }

    public function index(string $type, array $data): void
    {
        $this->security->protectedExecute(function() use ($type, $data) {
            $this->indexer->index($type, $data);
            $this->cache->invalidate($type);
        });
    }

    private function validateRequest(SearchRequest $request): void
    {
        if (empty($request->query)) {
            throw new SearchException('Empty search query');
        }

        if (strlen($request->query) > 100) {
            throw new SearchException('Query too long');
        }
    }

    private function executeQuery(SearchQuery $query): array
    {
        DB::beginTransaction();
        
        try {
            $results = DB::table($query->index)
                ->whereRaw($query->condition, $query->bindings)
                ->limit($query->limit)
                ->offset($query->offset)
                ->get();
                
            DB::commit();
            return $results->all();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SearchException('Query execution failed', 0, $e);
        }
    }
}

class SearchIndexer
{
    private array $config;
    
    public function index(string $type, array $data): void
    {
        $index = $this->config['indices'][$type] ?? null;
        if (!$index) {
            throw new SearchException("Invalid index type: $type");
        }

        DB::beginTransaction();
        try {
            $this->deleteExisting($type, $data['id']);
            $this->insertNew($type, $this->prepareData($data, $index));
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SearchException('Indexing failed', 0, $e);
        }
    }

    private function deleteExisting(string $type, int $id): void
    {
        DB::table($type . '_search_index')
            ->where('entity_id', $id)
            ->delete();
    }

    private function insertNew(string $type, array $data): void
    {
        DB::table($type . '_search_index')->insert($data);
    }

    private function prepareData(array $data, array $index): array
    {
        $prepared = [];
        
        foreach ($index['fields'] as $field => $config) {
            if (isset($data[$field])) {
                $prepared[$field] = $this->processField(
                    $data[$field],
                    $config['type']
                );
            }
        }

        return $prepared;
    }

    private function processField($value, string $type): mixed
    {
        return match($type) {
            'text' => $this->processText($value),
            'number' => (float)$value,
            'date' => $this->processDate($value),
            default => $value
        };
    }

    private function processText(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9\s]/', '', $text);
        return trim($text);
    }

    private function processDate($date): string
    {
        if ($date instanceof \DateTime) {
            return $date->format('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s', strtotime($date));
    }
}

class QueryBuilder
{
    private array $config;

    public function buildQuery(SearchRequest $request): SearchQuery
    {
        $index = $this->config['indices'][$request->type] ?? null;
        if (!$index) {
            throw new SearchException("Invalid index type: {$request->type}");
        }

        return new SearchQuery(
            index: $request->type . '_search_index',
            condition: $this->buildCondition($request, $index),
            bindings: $this->getBindings($request),
            limit: $request->limit,
            offset: $request->offset
        );
    }

    private function buildCondition(SearchRequest $request, array $index): string
    {
        $conditions = [];
        $fields = $index['fields'];

        foreach ($fields as $field => $config) {
            if ($config['searchable'] ?? false) {
                $conditions[] = $this->buildFieldCondition($field, $config['type']);
            }
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }

    private function buildFieldCondition(string $field, string $type): string
    {
        return match($type) {
            'text' => "$field LIKE ?",
            'number' => "$field = ?",
            'date' => "$field BETWEEN ? AND ?",
            default => "$field = ?"
        };
    }

    private function getBindings(SearchRequest $request): array
    {
        return [
            "%{$request->query}%",
            $request->query,
            $request->query
        ];
    }
}

class ResultProcessor
{
    public function process(array $results, SearchRequest $request): SearchResult
    {
        $processed = array_map(
            fn($result) => $this->processResult($result),
            $results
        );

        return new SearchResult(
            hits: $processed,
            total: count($processed),
            query: $request->query
        );
    }

    private function processResult($result): array
    {
        return [
            'id' => $result->id,
            'type' => $result->type,
            'title' => $result->title,
            'excerpt' => $result->excerpt,
            'url' => $result->url,
            'score' => $result->score
        ];
    }
}

class SearchCache
{
    private CacheManager $cache;
    private int $ttl;

    public function remember(string $key, callable $callback): mixed
    {
        return $this->cache->remember($key, $callback, $this->ttl);
    }

    public function generateKey(SearchRequest $request): string
    {
        return 'search.' . md5(serialize([
            'query' => $request->query,
            'type' => $request->type,
            'limit' => $request->limit,
            'offset' => $request->offset
        ]));
    }

    public function invalidate(string $type): void
    {
        $this->cache->tags(['search', "search.$type"])->flush();
    }
}

class SearchRequest
{
    public function __construct(
        public readonly string $query,
        public readonly string $type,
        public readonly int $limit = 10,
        public readonly int $offset = 0
    ) {}
}

class SearchQuery
{
    public function __construct(
        public readonly string $index,
        public readonly string $condition,
        public readonly array $bindings,
        public readonly int $limit,
        public readonly int $offset
    ) {}
}

class SearchResult
{
    public function __construct(
        public readonly array $hits,
        public readonly int $total,
        public readonly string $query
    ) {}
}

class SearchException extends \Exception {}
