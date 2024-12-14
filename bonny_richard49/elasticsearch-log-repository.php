<?php

namespace App\Core\Logging\Repository;

use App\Core\Logging\Models\LogEntry;
use App\Core\Logging\Search\IndexManager;
use App\Core\Logging\Search\SearchQuery;
use App\Core\Logging\ValueObjects\LogLevel;
use App\Core\Logging\Exceptions\LogStorageException;
use App\Core\Logging\Exceptions\LogBatchStorageException;
use DateTimeInterface;

class ElasticsearchLogRepository implements LogEntryRepositoryInterface
{
    private IndexManager $indexManager;
    private LogEntryFactory $factory;
    private MetricsCollector $metrics;

    public function __construct(
        IndexManager $indexManager,
        LogEntryFactory $factory,
        MetricsCollector $metrics
    ) {
        $this->indexManager = $indexManager;
        $this->factory = $factory;
        $this->metrics = $metrics;
    }

    public function store(LogEntry $entry): bool
    {
        try {
            $startTime = microtime(true);
            
            // Index the log entry
            $this->indexManager->index($entry);
            
            // Record metrics
            $this->recordMetrics('store', microtime(true) - $startTime);
            
            return true;
        } catch (\Exception $e) {
            $this->handleStorageError($e, $entry);
            throw new LogStorageException(
                "Failed to store log entry: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function storeBatch(array $entries): array
    {
        try {
            $startTime = microtime(true);
            
            // Validate entries
            $this->validateBatchEntries($entries);
            
            // Store batch
            $result = $this->indexManager->batchIndex($entries);
            
            // Record metrics
            $this->recordBatchMetrics($result, microtime(true) - $startTime);
            
            return $result->toArray();
        } catch (\Exception $e) {
            $this->handleBatchStorageError($e, $entries);
            throw new LogBatchStorageException(
                "Failed to store log entries batch: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function find(string $id): ?LogEntry
    {
        try {
            $startTime = microtime(true);
            
            // Build query
            $query = new SearchQuery();
            $query->whereEquals('id', $id);
            
            // Execute search
            $result = $this->indexManager->search($query);
            
            // Record metrics
            $this->recordMetrics('find', microtime(true) - $startTime);
            
            return !empty($result['hits']['hits']) 
                ? $this->factory->createFromArray($result['hits']['hits'][0]['_source'])
                : null;
        } catch (\Exception $e) {
            $this->handleSearchError($e);
            return null;
        }
    }

    public function findBy(SearchQuery $query): array
    {
        try {
            $startTime = microtime(true);
            
            // Execute search
            $results = $this->indexManager->search($query);
            
            // Convert results to LogEntry objects
            $entries = array_map(
                fn($hit) => $this->factory->createFromArray($hit['_source']),
                $results['hits']['hits']
            );
            
            // Record metrics
            $this->recordMetrics('findBy', microtime(true) - $startTime);
            
            return $entries;
        } catch (\Exception $e) {
            $this->handleSearchError($e);
            return [];
        }
    }

    public function findByDateRange(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        ?LogLevel $level = null,
        array $context = []
    ): array {
        // Build query
        $query = new SearchQuery();
        $query->whereBetween('timestamp', $startDate, $endDate);
        
        if ($level) {
            $query->whereEquals('level', $level->value);
        }
        
        if (!empty($context)) {
            foreach ($context as $key => $value) {
                $query->whereEquals("context.$key", $value);
            }
        }
        
        return $this->findBy($query);
    }

    public function count(SearchQuery $query): int
    {
        try {
            $startTime = microtime(true);
            
            // Execute count query
            $result = $this->indexManager->search($query->count());
            
            // Record metrics
            $this->recordMetrics('count', microtime(true) - $startTime);
            
            return $result['hits']['total']['value'] ?? 0;
        } catch (\Exception $e) {
            $this->handleSearchError($e);
            return 0;
        }
    }

    public function prune(DateTimeInterface $beforeDate): int
    {
        try {
            $startTime = microtime(true);
            
            // Build delete query
            $query = new SearchQuery();
            $query->whereLessThan('timestamp', $beforeDate);
            
            // Execute deletion
            $result = $this->indexManager->delete($query);
            
            // Record metrics
            $this->recordMetrics('prune', microtime(true) - $startTime);
            
            return $result['deleted'] ?? 0;
        } catch (\Exception $e) {
            $this->handleDeletionError($e);
            return 0;
        }
    }

    public function getStatistics(SearchQuery $query): array
    {
        try {
            $startTime = microtime(true);
            
            // Add aggregations to query
            $query->addAggregation('levels', ['field' => 'level'])
                  ->addAggregation('sources', ['field' => 'metadata.source'])
                  ->addAggregation('hourly', [
                      'date_histogram' => [
                          'field' => 'timestamp',
                          'calendar_interval' => 'hour'
                      ]
                  ]);
            
            // Execute search with aggregations
            $results = $this->indexManager->search($query);
            
            // Record metrics
            $this->recordMetrics('getStatistics', microtime(true) - $startTime);
            
            return $this->processAggregations($results['aggregations'] ?? []);
        } catch (\Exception $e) {
            $this->handleSearchError($e);
            return [];
        }
    }

    protected function validateBatchEntries(array $entries): void
    {
        foreach ($entries as $entry) {
            if (!$entry instanceof LogEntry) {
                throw new \InvalidArgumentException(
                    'All batch entries must be instances of LogEntry'
                );
            }
        }
    }

    protected function recordMetrics(string $operation, float $duration): void
    {
        $this->metrics->increment("logging.repository.$operation.total");
        $this->metrics->timing("logging.repository.$operation.duration", $duration);
    }

    protected function recordBatchMetrics(BatchResult $result, float $duration): void
    {
        $this->metrics->increment('logging.repository.batch.total');
        $this->metrics->timing('logging.repository.batch.duration', $duration);
        $this->metrics->gauge('logging.repository.batch.success_rate', $result->getSuccessRate());
    }

    protected function handleStorageError(\Exception $e, LogEntry $entry): void
    {
        $this->metrics->increment('logging.repository.errors');
        Log::error('Failed to store log entry', [
            'error' => $e->getMessage(),
            'entry_id' => $entry->getId()
        ]);
    }

    protected function handleBatchStorageError(\Exception $e, array $entries): void
    {
        $this->metrics->increment('logging.repository.batch.errors');
        Log::error('Failed to store log entries batch', [
            'error' => $e->getMessage(),
            'entries_count' => count($entries)
        ]);
    }

    protected function handleSearchError(\Exception $e): void
    {
        $this->metrics->increment('logging.repository.search.errors');
        Log::error('Log search failed', [
            'error' => $e->getMessage()
        ]);
    }

    protected function handleDeletionError(\Exception $e): void
    {
        $this->metrics->increment('logging.repository.deletion.errors');
        Log::error('Log deletion failed', [
            'error' => $e->getMessage()
        ]);
    }

    protected function processAggregations(array $aggregations): array
    {
        return [
            'by_level' => $this->processLevelAggregation($aggregations['levels'] ?? []),
            'by_source' => $this->processSourceAggregation($aggregations['sources'] ?? []),
            'by_hour' => $this->processTimeAggregation($aggregations['hourly'] ?? [])
        ];
    }
}
