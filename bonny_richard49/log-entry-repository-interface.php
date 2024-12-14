<?php

namespace App\Core\Logging\Repository;

use App\Core\Logging\Models\LogEntry;
use App\Core\Logging\ValueObjects\LogLevel;
use App\Core\Logging\Search\SearchQuery;
use DateTimeInterface;

interface LogEntryRepositoryInterface
{
    /**
     * Store a new log entry.
     *
     * @param LogEntry $entry
     * @return bool
     * @throws LogStorageException
     */
    public function store(LogEntry $entry): bool;

    /**
     * Store multiple log entries in a batch.
     *
     * @param LogEntry[] $entries
     * @return array Returns array of success/failure results
     * @throws LogBatchStorageException
     */
    public function storeBatch(array $entries): array;

    /**
     * Find a log entry by its ID.
     *
     * @param string $id
     * @return LogEntry|null
     */
    public function find(string $id): ?LogEntry;

    /**
     * Find log entries by criteria.
     *
     * @param SearchQuery $query
     * @return array
     */
    public function findBy(SearchQuery $query): array;

    /**
     * Find log entries within a date range.
     *
     * @param DateTimeInterface $startDate
     * @param DateTimeInterface $endDate
     * @param LogLevel|null $level
     * @param array $context
     * @return array
     */
    public function findByDateRange(
        DateTimeInterface $startDate,
        DateTimeInterface $endDate,
        ?LogLevel $level = null,
        array $context = []
    ): array;

    /**
     * Get count of log entries by criteria.
     *
     * @param SearchQuery $query
     * @return int
     */
    public function count(SearchQuery $query): int;

    /**
     * Delete old log entries based on retention policy.
     *
     * @param DateTimeInterface $beforeDate
     * @return int Number of deleted entries
     */
    public function prune(DateTimeInterface $beforeDate): int;

    /**
     * Get aggregated statistics for log entries.
     *
     * @param SearchQuery $query
     * @return array
     */
    public function getStatistics(SearchQuery $query): array;
}
