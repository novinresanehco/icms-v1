<?php

namespace App\Core\Events\Snapshots\AuditTrail;

class AuditTrailManager
{
    private AuditTrailStore $store;
    private AuditTrailLogger $logger;
    private AuditTrailValidator $validator;
    private AuditTrailMetrics $metrics;
    private LockManager $lockManager;

    public function __construct(
        AuditTrailStore $store,
        AuditTrailLogger $logger,
        AuditTrailValidator $validator,
        AuditTrailMetrics $metrics,
        LockManager $lockManager
    ) {
        $this->store = $store;
        $this->logger = $logger;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->lockManager = $lockManager;
    }

    public function recordTrail(TrailableEvent $event): TrailRecord
    {
        $lock = $this->lockManager->acquire("audit_trail:{$event->getIdentifier()}");

        try {
            $startTime = microtime(true);

            // Validate event
            $validationResult = $this->validator->validateEvent($event);
            if (!$validationResult->isValid()) {
                throw new AuditTrailException(
                    "Invalid event for audit trail: " . implode(", ", $validationResult->getErrors())
                );
            }

            // Create trail record
            $record = new TrailRecord(
                $event->getIdentifier(),
                $event->getType(),
                $event->getData(),
                $event->getMetadata(),
                new \DateTimeImmutable()
            );

            // Store record
            $this->store->saveRecord($record);

            // Log trail creation
            $this->logger->logTrailCreation($record);

            // Record metrics
            $this->metrics->recordTrailCreation(
                $record,
                microtime(true) - $startTime
            );

            return $record;

        } catch (\Exception $e) {
            $this->handleTrailError($event, $e);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function getTrailHistory(string $identifier): TrailHistory
    {
        return new TrailHistory(
            $identifier,
            $this->store->getRecordsForIdentifier($identifier)
        );
    }

    public function searchTrails(TrailSearchCriteria $criteria): TrailSearchResult
    {
        $startTime = microtime(true);

        try {
            $records = $this->store->searchRecords($criteria);
            
            $result = new TrailSearchResult($records, $criteria);

            $this->metrics->recordTrailSearch(
                $criteria,
                count($records),
                microtime(true) - $startTime
            );

            return $result;

        } catch (\Exception $e) {
            $this->handleSearchError($criteria, $e);
            throw $e;
        }
    }

    private function handleTrailError(TrailableEvent $event, \Exception $e): void
    {
        $this->logger->logTrailError($event, $e);
        $this->metrics->recordTrailError($event, $e);
    }

    private function handleSearchError(TrailSearchCriteria $criteria, \Exception $e): void
    {
        $this->logger->logSearchError($criteria, $e);
        $this->metrics->recordSearchError($criteria, $e);
    }
}

class TrailRecord
{
    private string $id;
    private string $identifier;
    private string $type;
    private array $data;
    private array $metadata;
    private \DateTimeImmutable $timestamp;

    public function __construct(
        string $identifier,
        string $type,
        array $data,
        array $metadata,
        \DateTimeImmutable $timestamp
    ) {
        $this->id = uniqid('trail_', true);
        $this->identifier = $identifier;
        $this->type = $type;
        $this->data = $data;
        $this->metadata = $metadata;
        $this->timestamp = $timestamp;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }
}

class TrailHistory
{
    private string $identifier;
    private array $records;

    public function __construct(string $identifier, array $records)
    {
        $this->identifier = $identifier;
        $this->records = $records;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getRecords(): array
    {
        return $this->records;
    }

    public function getTimeline(): array
    {
        return array_map(function ($record) {
            return [
                'timestamp' => $record->getTimestamp(),
                'type' => $record->getType(),
                'data' => $record->getData()
            ];
        }, $this->records);
    }

    public function findByType(string $type): array
    {
        return array_filter(
            $this->records,
            fn($record) => $record->getType() === $type
        );
    }
}

class TrailSearchCriteria
{
    private array $filters;
    private array $dateRange;
    private int $limit;
    private int $offset;

    public function __construct(array $filters, array $dateRange, int $limit = 50, int $offset = 0)
    {
        $this->filters = $filters;
        $this->dateRange = $dateRange;
        $this->limit = $limit;
        $this->offset = $offset;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getDateRange(): array
    {
        return $this->dateRange;
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

class TrailSearchResult
{
    private array $records;
    private TrailSearchCriteria $criteria;
    private array $aggregations;

    public function __construct(array $records, TrailSearchCriteria $criteria)
    {
        $this->records = $records;
        $this->criteria = $criteria;
        $this->aggregations = $this->calculateAggregations();
    }

    public function getRecords(): array
    {
        return $this->records;
    }

    public function getCriteria(): TrailSearchCriteria
    {
        return $this->criteria;
    }

    public function getAggregations(): array
    {
        return $this->aggregations;
    }

    private function calculateAggregations(): array
    {
        return [
            'total' => count($this->records),
            'types' => $this->aggregateByType(),
            'timeline' => $this->aggregateByTimeline()
        ];
    }

    private function aggregateByType(): array
    {
        $types = [];
        foreach ($this->records as $record) {
            $type = $record->getType();
            $types[$type] = ($types[$type] ?? 0) + 1;
        }
        return $types;
    }

    private function aggregateByTimeline(): array
    {
        $timeline = [];
        foreach ($this->records as $record) {
            $date = $record->getTimestamp()->format('Y-m-d');
            $timeline[$date] = ($timeline[$date] ?? 0) + 1;
        }
        return $timeline;
    }
}

interface TrailableEvent
{
    public function getIdentifier(): string;
    public function getType(): string;
    public function getData(): array;
    public function getMetadata(): array;
}

class AuditTrailException extends \Exception {}
