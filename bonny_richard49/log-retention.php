<?php

namespace App\Core\Logging\Retention;

class LogRetentionManager implements RetentionManagerInterface 
{
    private StorageManager $storage;
    private ArchiveManager $archive;
    private RetentionPolicy $policy;
    private MetricsCollector $metrics;
    private LoggerInterface $logger;

    public function __construct(
        StorageManager $storage,
        ArchiveManager $archive,
        RetentionPolicy $policy,
        MetricsCollector $metrics,
        LoggerInterface $logger
    ) {
        $this->storage = $storage;
        $this->archive = $archive;
        $this->policy = $policy;
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function executeRetentionPolicy(): RetentionResult
    {
        $result = new RetentionResult();

        try {
            // Begin retention process
            $this->logger->info('Starting log retention process');
            $startTime = microtime(true);

            // Get logs eligible for cleanup
            $logsToProcess = $this->getLogsForRetention();

            // Process each log entry
            foreach ($logsToProcess as $log) {
                $this->processLogRetention($log, $result);
            }

            // Update metrics
            $this->updateRetentionMetrics($result, microtime(true) - $startTime);

            // Log completion
            $this->logger->info('Log retention process completed', [
                'processed' => $result->getProcessedCount(),
                'archived' => $result->getArchivedCount(),
                'deleted' => $result->getDeletedCount(),
                'duration' => $result->getDuration()
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->handleRetentionError($e, $result);
            throw $e;
        }
    }

    protected function getLogsForRetention(): Collection
    {
        return $this->storage->getLogsByQuery(
            $this->buildRetentionQuery()
        );
    }

    protected function processLogRetention(LogEntry $log, RetentionResult $result): void
    {
        try {
            $action = $this->policy->determineAction($log);

            switch ($action) {
                case RetentionAction::ARCHIVE:
                    $this->archiveLog($log);
                    $result->incrementArchived();
                    break;

                case RetentionAction::DELETE:
                    $this->deleteLog($log);
                    $result->incrementDeleted();
                    break;

                case RetentionAction::COMPRESS:
                    $this->compressLog($log);
                    $result->incrementCompressed();
                    break;

                default:
                    $result->incrementSkipped();
            }

            $result->incrementProcessed();

        } catch (\Exception $e) {
            $this->handleProcessingError($log, $e, $result);
        }
    }

    protected function archiveLog(LogEntry $log): void
    {
        DB::transaction(function () use ($log) {
            // Archive the log
            $archived = $this->archive->archiveLog($log);

            // Delete original after successful archive
            if ($archived) {
                $this->storage->delete($log);
            }

            // Record archival
            $this->recordArchival($log);
        });
    }

    protected function deleteLog(LogEntry $log): void
    {
        // Record deletion metadata before deleting
        $this->recordDeletion($log);

        // Delete the log
        $this->storage->delete($log);
    }

    protected function compressLog(LogEntry $log): void
    {
        // Compress log content
        $compressed = $this->storage->compress($log);

        // Update log record
        $this->storage->update($log, [
            'compressed' => true,
            'original_size' => $log->size,
            'compressed_size' => $compressed->size
        ]);
    }

    protected function buildRetentionQuery(): LogQuery
    {
        return (new LogQueryBuilder())
            ->whereOlderThan($this->policy->getRetentionAge())
            ->whereNotArchived()
            ->whereNotExcluded($this->policy->getExcludedTypes())
            ->orderByDate('asc')
            ->limit($this->policy->getBatchSize());
    }

    protected function recordArchival(LogEntry $log): void
    {
        $this->metrics->increment('logs.archived');
        $this->metrics->gauge('logs.archived_size', $log->size);

        $this->logger->info('Log archived', [
            'log_id' => $log->id,
            'age' => $log->age->days,
            'size' => $log->size
        ]);
    }

    protected function recordDeletion(LogEntry $log): void
    {
        $this->metrics->increment('logs.deleted');
        $this->metrics->gauge('logs.deleted_size', $log->size);

        $this->logger->info('Log deleted', [
            'log_id' => $log->id,
            'age' => $log->age->days
        ]);
    }

    protected function updateRetentionMetrics(RetentionResult $result, float $duration): void
    {
        $this->metrics->gauge('retention.processed', $result->getProcessedCount());
        $this->metrics->gauge('retention.archived', $result->getArchivedCount());
        $this->metrics->gauge('retention.deleted', $result->getDeletedCount());
        $this->metrics->gauge('retention.compressed', $result->getCompressedCount());
        $this->metrics->gauge('retention.duration', $duration);

        // Calculate success rate
        $successRate = ($result->getProcessedCount() > 0)
            ? ($result->getSuccessCount() / $result->getProcessedCount()) * 100
            : 100;

        $this->metrics->gauge('retention.success_rate', $successRate);
    }

    protected function handleProcessingError(LogEntry $log, \Exception $e, RetentionResult $result): void
    {
        $this->logger->error('Error processing log retention', [
            'log_id' => $log->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $result->incrementFailed();
        $result->addError($e->getMessage());

        $this->metrics->increment('retention.errors');
    }

    protected function handleRetentionError(\Exception $e, RetentionResult $result): void
    {
        $this->logger->error('Log retention process failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'processed' => $result->getProcessedCount()
        ]);

        $this->metrics->increment('retention.process_failures');

        // Notify admin of failure
        if ($this->shouldNotifyAdmin($e)) {
            $this->notifyAdminOfFailure($e, $result);
        }
    }

    protected function shouldNotifyAdmin(\Exception $e): bool
    {
        return $e instanceof CriticalRetentionException ||
               $e instanceof StorageException;
    }

    protected function notifyAdminOfFailure(\Exception $e, RetentionResult $result): void
    {
        // Implementation of admin notification logic
    }
}
