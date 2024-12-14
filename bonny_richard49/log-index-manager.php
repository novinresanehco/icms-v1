<?php

namespace App\Core\Logging\Search;

class IndexManager implements IndexManagerInterface
{
    private Storage $storage;
    private IndexOptimizer $optimizer;
    private SchemaManager $schemaManager;
    private MetricsCollector $metrics;
    private Config $config;

    public function __construct(
        Storage $storage,
        IndexOptimizer $optimizer,
        SchemaManager $schemaManager,
        MetricsCollector $metrics,
        Config $config
    ) {
        $this->storage = $storage;
        $this->optimizer = $optimizer;
        $this->schemaManager = $schemaManager;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function index(LogEntry $entry): void 
    {
        $startTime = microtime(true);

        try {
            // Prepare document for indexing
            $document = $this->prepareDocument($entry);

            // Get target index
            $index = $this->determineTargetIndex($entry);

            // Optimize document if needed
            if ($this->shouldOptimize($document)) {
                $document = $this->optimizer->optimize($document);
            }

            // Index document
            $this->storage->index($index, $document);

            // Update metrics
            $this->recordIndexingMetrics($document, microtime(true) - $startTime);

        } catch (\Exception $e) {
            $this->handleIndexingError($entry, $e);
        }
    }

    public function batchIndex(array $entries): BatchResult
    {
        $result = new BatchResult();
        $startTime = microtime(true);

        try {
            // Group entries by target index
            $groupedEntries = $this->groupEntriesByIndex($entries);

            // Process each group
            foreach ($groupedEntries as $index => $indexEntries) {
                $this->processBatchGroup($index, $indexEntries, $result);
            }

            // Update batch metrics
            $this->recordBatchMetrics($result, microtime(true) - $startTime);

            return $result;

        } catch (\Exception $e) {
            $this->handleBatchError($entries, $e, $result);
            throw $e;
        }
    }

    public function search(SearchQuery $query): array
    {
        // Execute search
        $results = $this->storage->search(
            $query->getIndices(),
            $query->toArray()
        );

        // Record search metrics
        $this->recordSearchMetrics($query, $results);

        return $results;
    }

    public function refresh(string $index): void
    {
        try {
            // Refresh index
            $this->storage->refresh($index);

            // Update metrics
            $this->metrics->increment('index.refreshes');

        } catch (\Exception $e) {
            $this->handleRefreshError($index, $e);
        }
    }

    protected function prepareDocument(LogEntry $entry): array
    {
        return [
            'id' => $entry->getId(),
            'timestamp' => $entry->getTimestamp()->toIso8601String(),
            'level' => $entry->getLevel(),
            'message' => $entry->getMessage(),
            'context' => $this->prepareContext($entry->getContext()),
            'extra' => $entry->getExtra(),
            'metadata' => [
                'type' => $entry->getType(),
                'source' => $entry->getSource(),
                'environment' => $entry->getEnvironment()
            ]
        ];
    }

    protected function determineTargetIndex(LogEntry $entry): string
    {
        $pattern = $this->config->get(
            'logging.index_pattern',
            'logs-{yyyy.MM.dd}'
        );

        return $this->formatIndexPattern(
            $pattern,
            $entry->getTimestamp()
        );
    }

    protected function shouldOptimize(array $document): bool
    {
        return strlen(json_encode($document)) > $this->config->get(
            'logging.optimization_threshold',
            1024 * 10 // 10KB
        );
    }

    protected function processBatchGroup(
        string $index,
        array $entries,
        BatchResult $result
    ): void {
        try {
            // Prepare documents
            $documents = array_map(
                fn($entry) => $this->prepareDocument($entry),
                $entries
            );

            // Optimize if needed
            if ($this->shouldOptimizeBatch($documents)) {
                $documents = $this->optimizer->optimizeBatch($documents);
            }

            // Index documents
            $response = $this->storage->bulkIndex($index, $documents);

            // Process response
            $this->processBulkResponse($response, $result);

        } catch (\Exception $e) {
            $this->handleBatchGroupError($index, $entries, $e, $result);
        }
    }

    protected function groupEntriesByIndex(array $entries): array
    {
        $grouped = [];

        foreach ($entries as $entry) {
            $index = $this->determineTargetIndex($entry);
            $grouped[$index][] = $entry;
        }

        return $grouped;
    }

    protected function processBulkResponse(array $response, BatchResult $result): void
    {
        foreach ($response['items'] as $item) {
            if (isset($item['index']['error'])) {
                $result->addError($item['index']['error']);
                $result->incrementFailureCount();
            } else {
                $result->incrementSuccessCount();
            }
        }
    }

    protected function recordIndexingMetrics(array $document, float $duration): void
    {
        $this->metrics->increment('index.documents');
        $this->metrics->timing('index.duration', $duration);
        $this->metrics->gauge('index.document_size', strlen(json_encode($document)));
    }

    protected function recordBatchMetrics(BatchResult $result, float $duration): void
    {
        $this->metrics->increment('index.batches');
        $this->metrics->timing('index.batch_duration', $duration);
        $this->metrics->gauge('index.batch_success_rate', $result->getSuccessRate());
        $this->metrics->gauge('index.batch_size', $result->getTotalCount());
    }

    protected function handleIndexingError(LogEntry $entry, \Exception $e): void
    {
        // Log error
        Log::error('Document indexing failed', [
            'entry_id' => $entry->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Update metrics
        $this->metrics->increment('index.errors');

        // Notify if critical
        if ($this->isCriticalError($e)) {
            $this->notifyIndexingError($entry, $e);
        }
    }
}
