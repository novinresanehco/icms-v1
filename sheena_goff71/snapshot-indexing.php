<?php

namespace App\Core\Events\Snapshots\Indexing;

class SnapshotIndexManager
{
    private IndexStore $store;
    private SearchEngine $searchEngine;
    private IndexOptimizer $optimizer;
    private IndexMetrics $metrics;

    public function __construct(
        IndexStore $store,
        SearchEngine $searchEngine,
        IndexOptimizer $optimizer,
        IndexMetrics $metrics
    ) {
        $this->store = $store;
        $this->searchEngine = $searchEngine;
        $this->optimizer = $optimizer;
        $this->metrics = $metrics;
    }

    public function indexSnapshot(Snapshot $snapshot): IndexedSnapshot
    {
        $startTime = microtime(true);

        try {
            // Extract searchable data
            $searchableData = $this->extractSearchableData($snapshot);
            
            // Generate index entries
            $indexEntries = $this->generateIndexEntries($searchableData);
            
            // Store index entries
            $this->store->storeEntries($snapshot->getAggregateId(), $indexEntries);
            
            // Index in search engine
            $this->searchEngine->index($snapshot->getAggregateId(), $searchableData);

            $indexed = new IndexedSnapshot($snapshot, $indexEntries);
            
            $this->metrics->recordIndexing(
                $snapshot,
                count($indexEntries),
                microtime(true) - $startTime
            );

            return $indexed;

        } catch (\Exception $e) {
            $this->metrics->recordIndexingFailure($snapshot, $e);
            throw new IndexingException("Indexing failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function search(SearchCriteria $criteria): SearchResult
    {
        $startTime = microtime(true);

        try {
            // Search through index
            $results = $this->searchEngine->search($criteria);
            
            // Load snapshots for results
            $snapshots = $this->loadSnapshots($results);
            
            // Sort and filter results
            $sortedResults = $this->sortResults($snapshots, $criteria->getSortOrder());

            $this->metrics->recordSearch($criteria, count($sortedResults), microtime(true) - $startTime);

            return new SearchResult($sortedResults, $criteria);

        } catch (\Exception $e) {
            $this->metrics->recordSearchFailure($criteria, $e);
            throw new SearchException("Search failed: " . $e->getMessage(), 0, $e);
        }
    }

    private function extractSearchableData(Snapshot $snapshot): array
    {
        $state = $snapshot->getState();
        return [
            'aggregate_id' => $snapshot->getAggregateId(),
            'version' => $snapshot->getVersion(),
            'created_at' => $snapshot->getCreatedAt()->format('Y-m-d H:i:s'),
            'metadata' => $snapshot->getMetadata(),
            'state_summary' => $this->summarizeState($state)
        ];
    }

    private function summarizeState($state): array
    {
        // Implementation to extract searchable summary from state
        return [];
    }

    private function generateIndexEntries(array $data): array
    {
        $entries = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $entries = array_merge(
                    $entries,
                    $this->generateEntriesFromArray($key, $value)
                );
            } else {
                $entries[] = new IndexEntry($key, $value);
            }
        }
        return $entries;
    }

    private function loadSnapshots(array $results): array
    {
        return array_map(
            fn($result) => $this->store->getSnapshot($result['aggregate_id']),
            $results
        );
    }

    private function sortResults(array $snapshots, string $order): array
    {
        usort($snapshots, fn($a, $b) => $this->compareSnapshots($a, $b, $order));
        return $snapshots;
    }
}

class SearchCriteria
{
    private array $filters = [];
    private array $sort = [];
    private int $limit;
    private int $offset;

    public function __construct(int $limit = 100, int $offset = 0)
    {
        $this->limit = $limit;
        $this->offset = $offset;
    }

    public function addFilter(string $field, string $operator, $value): self
    {
        $this->filters[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value
        ];
        return $this;
    }

    public function addSort(string $field, string $direction = 'asc'): self
    {
        $this->sort[$field] = $direction;
        return $this;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getSort(): array
    {
        return $this->sort;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }
}

class SearchResult
{
    private array $snapshots;
    private SearchCriteria $criteria;
    private int $total;

    public function __construct(array $snapshots, SearchCriteria $criteria)
    {
        $this->snapshots = $snapshots;
        $this->criteria = $criteria;
        $this->total = count($snapshots);
    }

    public function getSnapshots(): array
    {
        return $this->snapshots;
    }

    public function getCriteria(): SearchCriteria
    {
        return $this->criteria;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function hasMore(): bool
    {
        return ($this->criteria->getOffset() + count($this->snapshots)) < $this->total;
    }
}

class IndexEntry
{
    private string $key;
    private mixed $value;
    private array $metadata;

    public function __construct(string $key, mixed $value, array $metadata = [])
    {
        $this->key = $key;
        $this->value = $value;
        $this->metadata = $metadata;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class IndexedSnapshot
{
    private Snapshot $snapshot;
    private array $indexEntries;
    private \DateTimeImmutable $indexedAt;

    public function __construct(Snapshot $snapshot, array $indexEntries)
    {
        $this->snapshot = $snapshot;
        $this->indexEntries = $indexEntries;
        $this->indexedAt = new \DateTimeImmutable();
    }

    public function getSnapshot(): Snapshot
    {
        return $this->snapshot;
    }

    public function getIndexEntries(): array
    {
        return $this->indexEntries;
    }

    public function getIndexedAt(): \DateTimeImmutable
    {
        return $this->indexedAt;
    }
}

class IndexMetrics
{
    private MetricsCollector $collector;

    public function recordIndexing(Snapshot $snapshot, int $entryCount, float $duration): void
    {
        $this->collector->timing('snapshot.indexing.duration', $duration * 1000, [
            'aggregate_id' => $snapshot->getAggregateId()
        ]);

        $this->collector->gauge('snapshot.indexing.entries', $entryCount, [
            'aggregate_id' => $snapshot->getAggregateId()
        ]);

        $this->collector->increment('snapshot.indexing.completed');
    }

    public function recordIndexingFailure(Snapshot $snapshot, \Exception $error): void
    {
        $this->collector->increment('snapshot.indexing.failed', [
            'aggregate_id' => $snapshot->getAggregateId(),
            'error_type' => get_class($error)
        ]);
    }

    public function recordSearch(SearchCriteria $criteria, int $resultCount, float $duration): void
    {
        $this->collector->timing('snapshot.search.duration', $duration * 1000);
        $this->collector->gauge('snapshot.search.results', $resultCount);
        $this->collector->increment('snapshot.search.completed');
    }

    public function recordSearchFailure(SearchCriteria $criteria, \Exception $error): void
    {
        $this->collector->increment('snapshot.search.failed', [
            'error_type' => get_class($error)
        ]);
    }
}

class IndexingException extends \Exception {}
class SearchException extends \Exception {}

