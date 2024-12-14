namespace App\Core\Search;

class SearchEngine implements SearchInterface
{
    private SecurityManager $security;
    private IndexManager $indexer;
    private CacheManager $cache;
    private ValidationService $validator;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        IndexManager $indexer,
        CacheManager $cache,
        ValidationService $validator,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->indexer = $indexer;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->metrics = $metrics;
    }

    public function search(SearchQuery $query): SearchResults
    {
        return $this->security->executeCriticalOperation(new class($query, $this->indexer, $this->cache, $this->metrics) implements CriticalOperation {
            private SearchQuery $query;
            private IndexManager $indexer;
            private CacheManager $cache;
            private MetricsCollector $metrics;

            public function __construct(SearchQuery $query, IndexManager $indexer, CacheManager $cache, MetricsCollector $metrics)
            {
                $this->query = $query;
                $this->indexer = $indexer;
                $this->cache = $cache;
                $this->metrics = $metrics;
            }

            public function execute(): OperationResult
            {
                $cacheKey = "search:" . md5(serialize($this->query));
                
                $startTime = microtime(true);
                $results = $this->cache->remember($cacheKey, function() {
                    return $this->indexer->search(
                        $this->query->getTerm(),
                        $this->query->getFilters(),
                        $this->query->getOptions()
                    );
                });

                $this->metrics->recordSearchMetrics(
                    $this->query,
                    $results->count(),
                    microtime(true) - $startTime
                );

                return new OperationResult($results);
            }

            public function getValidationRules(): array
            {
                return [
                    'term' => 'required|string|min:2',
                    'filters' => 'array',
                    'options' => 'array'
                ];
            }

            public function getData(): array
            {
                return [
                    'term' => $this->query->getTerm(),
                    'filter_count' => count($this->query->getFilters())
                ];
            }

            public function getRequiredPermissions(): array
            {
                return ['search.execute'];
            }

            public function getRateLimitKey(): string
            {
                return "search:query:" . md5($this->query->getTerm());
            }
        });
    }

    public function index(Indexable $item): void
    {
        $this->security->executeCriticalOperation(new class($item, $this->indexer, $this->validator) implements CriticalOperation {
            private Indexable $item;
            private IndexManager $indexer;
            private ValidationService $validator;

            public function __construct(Indexable $item, IndexManager $indexer, ValidationService $validator)
            {
                $this->item = $item;
                $this->indexer = $indexer;
                $this->validator = $validator;
            }

            public function execute(): OperationResult
            {
                $document = $this->item->toSearchDocument();
                $this->validator->validateDocument($document);
                
                $this->indexer->index($document);
                return new OperationResult(true);
            }

            public function getValidationRules(): array
            {
                return [
                    'id' => 'required',
                    'type' => 'required|string',
                    'content' => 'required|array'
                ];
            }

            public function getData(): array
            {
                return [
                    'type' => $this->item->getSearchType(),
                    'id' => $this->item->getSearchId()
                ];
            }

            public function getRequiredPermissions(): array
            {
                return ['search.index'];
            }

            public function getRateLimitKey(): string
            {
                return "search:index:{$this->item->getSearchType()}";
            }
        });
    }

    public function reindex(string $type = null): void
    {
        $this->security->executeCriticalOperation(new class($type, $this->indexer) implements CriticalOperation {
            private ?string $type;
            private IndexManager $indexer;

            public function __construct(?string $type, IndexManager $indexer)
            {
                $this->type = $type;
                $this->indexer = $indexer;
            }

            public function execute(): OperationResult
            {
                if ($this->type) {
                    $this->indexer->reindexType($this->type);
                } else {
                    $this->indexer->reindexAll();
                }
                
                return new OperationResult(true);
            }

            public function getValidationRules(): array
            {
                return ['type' => 'nullable|string'];
            }

            public function getData(): array
            {
                return ['type' => $this->type];
            }

            public function getRequiredPermissions(): array
            {
                return ['search.reindex'];
            }

            public function getRateLimitKey(): string
            {
                return 'search:reindex:' . ($this->type ?? 'all');
            }
        });
    }

    public function optimize(): void
    {
        $this->security->executeCriticalOperation(new class($this->indexer) implements CriticalOperation {
            private IndexManager $indexer;

            public function __construct(IndexManager $indexer)
            {
                $this->indexer = $indexer;
            }

            public function execute(): OperationResult
            {
                $this->indexer->optimize();
                return new OperationResult(true);
            }

            public function getValidationRules(): array
            {
                return [];
            }

            public function getData(): array
            {
                return [];
            }

            public function getRequiredPermissions(): array
            {
                return ['search.optimize'];
            }

            public function getRateLimitKey(): string
            {
                return 'search:optimize';
            }
        });
    }

    public function getStats(): array
    {
        return $this->security->executeCriticalOperation(new class($this->indexer, $this->metrics) implements CriticalOperation {
            private IndexManager $indexer;
            private MetricsCollector $metrics;

            public function __construct(IndexManager $indexer, MetricsCollector $metrics)
            {
                $this->indexer = $indexer;
                $this->metrics = $metrics;
            }

            public function execute(): OperationResult
            {
                return new OperationResult([
                    'index_stats' => $this->indexer->getStats(),
                    'search_metrics' => $this->metrics->getSearchMetrics()
                ]);
            }

            public function getValidationRules(): array
            {
                return [];
            }

            public function getData(): array
            {
                return [];
            }

            public function getRequiredPermissions(): array
            {
                return ['search.stats'];
            }

            public function getRateLimitKey(): string
            {
                return 'search:stats';
            }
        });
    }
}
