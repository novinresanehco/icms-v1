<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Content\ContentRepository;

class SearchManager implements SearchInterface
{
    protected SecurityManager $security;
    protected ContentRepository $content;
    protected IndexManager $index;
    protected SearchAnalyzer $analyzer;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        ContentRepository $content,
        IndexManager $index,
        SearchAnalyzer $analyzer,
        array $config
    ) {
        $this->security = $security;
        $this->content = $content;
        $this->index = $index;
        $this->analyzer = $analyzer;
        $this->config = $config;
    }

    public function search(array $criteria): SearchResults
    {
        return $this->security->executeCriticalOperation(function() use ($criteria) {
            $this->validateCriteria($criteria);
            $cacheKey = $this->generateCacheKey($criteria);
            
            return Cache::tags(['search'])->remember($cacheKey, function() use ($criteria) {
                $terms = $this->analyzer->analyze($criteria['query']);
                $results = $this->index->search($terms, $criteria);
                
                return $this->processResults($results, $criteria);
            });
        });
    }

    public function index(ContentEntity $content): void
    {
        $this->security->executeCriticalOperation(function() use ($content) {
            DB::transaction(function() use ($content) {
                $tokens = $this->analyzer->tokenize($content);
                $this->index->indexContent($content->id, $tokens);
                Cache::tags(['search'])->flush();
            });
        });
    }

    public function reindex(): void
    {
        $this->security->executeCriticalOperation(function() {
            DB::transaction(function() {
                $this->index->clear();
                
                $contents = $this->content->getAllPublished();
                foreach ($contents as $content) {
                    $this->index($content);
                }
                
                Cache::tags(['search'])->flush();
            });
        });
    }

    public function suggest(string $query): array
    {
        return $this->security->executeCriticalOperation(function() use ($query) {
            $cacheKey = "suggestions:{$query}";
            
            return Cache::tags(['search'])->remember($cacheKey, function() use ($query) {
                $terms = $this->analyzer->analyze($query);
                return $this->index->suggest($terms);
            });
        });
    }

    protected function validateCriteria(array $criteria): void
    {
        $rules = [
            'query' => 'required|string|min:2',
            'type' => 'string|in:' . implode(',', $this->config['allowed_types']),
            'filters' => 'array',
            'sort' => 'string|in:' . implode(',', $this->config['allowed_sort_fields']),
            'order' => 'string|in:asc,desc',
            'limit' => 'integer|min:1|max:' . $this->config['max_results'],
            'offset' => 'integer|min:0'
        ];

        $validator = validator($criteria, $rules);
        
        if ($validator->fails()) {
            throw new InvalidSearchCriteriaException($validator->errors()->first());
        }
    }

    protected function processResults(array $results, array $criteria): SearchResults
    {
        $contentIds = array_column($results, 'content_id');
        $contents = $this->content->findMany($contentIds);
        
        $processed = [];
        foreach ($results as $result) {
            if (isset($contents[$result['content_id']])) {
                $content = $contents[$result['content_id']];
                
                if ($this->security->canView($content)) {
                    $processed[] = new SearchResult(
                        $content,
                        $result['score'],
                        $result['highlights']
                    );
                }
            }
        }

        return new SearchResults(
            $processed,
            $this->index->getTotalCount($criteria),
            $criteria
        );
    }

    protected function generateCacheKey(array $criteria): string
    {
        $normalized = array_merge([
            'type' => null,
            'filters' => [],
            'sort' => 'relevance',
            'order' => 'desc',
            'limit' => $this->config['default_limit'],
            'offset' => 0
        ], $criteria);
        
        ksort($normalized);
        return 'search:' . md5(serialize($normalized));
    }

    public function optimize(): void
    {
        $this->security->executeCriticalOperation(function() {
            DB::transaction(function() {
                $this->index->optimize();
                Cache::tags(['search'])->flush();
            });
        });
    }

    public function stats(): array
    {
        return $this->security->executeCriticalOperation(function() {
            return Cache::tags(['search'])->remember('search:stats', function() {
                return [
                    'total_documents' => $this->index->getDocumentCount(),
                    'total_terms' => $this->index->getTermCount(),
                    'last_optimization' => $this->index->getLastOptimization(),
                    'index_size' => $this->index->getSize(),
                    'average_response_time' => $this->index->getAverageResponseTime()
                ];
            });
        });
    }
}
