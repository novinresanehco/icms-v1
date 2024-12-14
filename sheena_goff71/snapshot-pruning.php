<?php

namespace App\Core\Events\Snapshots\Pruning;

class SnapshotPruner
{
    private PruningStrategy $strategy;
    private SnapshotStore $store;
    private PruningLogger $logger;
    private PruningMetrics $metrics;

    public function __construct(
        PruningStrategy $strategy,
        SnapshotStore $store,
        PruningLogger $logger,
        PruningMetrics $metrics
    ) {
        $this->strategy = $strategy;
        $this->store = $store;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function prune(string $aggregateId, PruningOptions $options): PruningResult
    {
        $this->logger->startPruning($aggregateId);
        $startTime = microtime(true);

        try {
            // Get all snapshots for aggregate
            $snapshots = $this->store->getSnapshots($aggregateId);
            
            // Determine which snapshots to keep
            $snapshotsToKeep = $this->strategy->selectSnapshotsToKeep($snapshots, $options);
            
            // Identify snapshots to remove
            $snapshotsToRemove = array_diff_key($snapshots, $snapshotsToKeep);
            
            if (!$options->isDryRun()) {
                // Remove snapshots
                foreach ($snapshotsToRemove as $snapshot) {
                    $this->store->delete($snapshot->getId());
                }
            }

            $result = new PruningResult(
                $aggregateId,
                count($snapshots),
                count($snapshotsToRemove),
                $options->isDryRun()
            );

            $this->metrics->recordPruning(
                $result,
                microtime(true) - $startTime
            );

            $this->logger->completePruning($result);

            return $result;

        } catch (\Exception $e) {
            $this->handlePruningFailure($aggregateId, $e);
            throw $e;
        }
    }

    public function pruneAll(PruningOptions $options): void
    {
        $aggregates = $this->store->getAllAggregateIds();
        
        foreach ($aggregates as $aggregateId) {
            try {
                $this->prune($aggregateId, $options);
            } catch (\Exception $e) {
                $this->logger->pruningError($aggregateId, $e);
                if (!$options->shouldContinueOnError()) {
                    throw $e;
                }
            }
        }
    }

    private function handlePruningFailure(string $aggregateId, \Exception $e): void
    {
        $this->logger->pruningError($aggregateId, $e);
        $this->metrics->recordPruningFailure($aggregateId, $e);
    }
}

class PruningStrategy
{
    public function selectSnapshotsToKeep(array $snapshots, PruningOptions $options): array
    {
        $keep = [];

        // Always keep latest snapshot
        $latest = $this->getLatestSnapshot($snapshots);
        if ($latest) {
            $keep[$latest->getId()] = $latest;
        }

        // Keep snapshots based on age
        if ($options->getMaxAge()) {
            $keep += $this->selectByAge($snapshots, $options->getMaxAge());
        }

        // Keep snapshots based on count
        if ($options->getMaxCount()) {
            $keep += $this->selectByCount($snapshots, $options->getMaxCount());
        }

        // Keep snapshots based on size
        if ($options->getMaxSize()) {
            $keep += $this->selectBySize($snapshots, $options->getMaxSize());
        }

        return $keep;
    }

    private function getLatestSnapshot(array $snapshots): ?Snapshot
    {
        if (empty($snapshots)) {
            return null;
        }

        return array_reduce($snapshots, function($latest, $snapshot) {
            if (!$latest || $snapshot->getVersion() > $latest->getVersion()) {
                return $snapshot;
            }
            return $latest;
        });
    }

    private function selectByAge(array $snapshots, int $maxAgeDays): array
    {
        $threshold = new \DateTimeImmutable("-{$maxAgeDays} days");
        
        return array_filter($snapshots, function($snapshot) use ($threshold) {
            return $snapshot->getCreatedAt() > $threshold;
        });
    }

    private function selectByCount(array $snapshots, int $maxCount): array
    {
        usort($snapshots, fn($a, $b) => $b->getVersion() <=> $a->getVersion());
        return array_slice($snapshots, 0, $maxCount, true);
    }

    private function selectBySize(array $snapshots, int $maxSizeBytes): array
    {
        usort($snapshots, fn($a, $b) => $b->getVersion() <=> $a->getVersion());
        
        $keep = [];
        $totalSize = 0;

        foreach ($snapshots as $snapshot) {
            $newTotal = $totalSize + $snapshot->getSize();
            if ($newTotal > $maxSizeBytes) {
                break;
            }
            $keep[$snapshot->getId()] = $snapshot;
            $totalSize = $newTotal;
        }

        return $keep;
    }
}

class PruningOptions
{
    private ?int $maxAge = null;
    private ?int $maxCount = null;
    private ?int $maxSize = null;
    private bool $dryRun = false;
    private bool $continueOnError = false;

    public function setMaxAge(int $days): self
    {
        $this->maxAge = $days;
        return $this;
    }

    public function setMaxCount(int $count): self
    {
        $this->maxCount = $count;
        return $this;
    }

    public function setMaxSize(int $bytes): self
    {
        $this->maxSize = $bytes;
        return $this;
    }

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    public function setContinueOnError(bool $continue): self
    {
        $this->continueOnError = $continue;
        return $this;
    }

    public function getMaxAge(): ?int
    {
        return $this->maxAge;
    }

    public function getMaxCount(): ?int
    {
        return $this->maxCount;
    }

    public function getMaxSize(): ?int
    {
        return $this->maxSize;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function shouldContinueOnError(): bool
    {
        return $this->continueOnError;
    }
}

class PruningResult
{
    private string $aggregateId;
    private int $totalSnapshots;
    private int $snapshotsRemoved;
    private bool $isDryRun;
    private \DateTimeImmutable $completedAt;

    public function __construct(
        string $aggregateId,
        int $totalSnapshots,
        int $snapshotsRemoved,
        bool $isDryRun
    ) {
        $this->aggregateId = $aggregateId;
        $this->totalSnapshots = $totalSnapshots;
        $this->snapshotsRemoved = $snapshotsRemoved;
        $this->isDryRun = $isDryRun;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getTotalSnapshots(): int
    {
        return $this->totalSnapshots;
    }

    public function getSnapshotsRemoved(): int
    {
        return $this->snapshotsRemoved;
    }

    public function isDryRun(): bool
    {
        return $this->isDryRun;
    }

    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }
}

class PruningMetrics
{
    private MetricsCollector $collector;

    public function recordPruning(PruningResult $result, float $duration): void
    {
        $this->collector->timing('snapshot.pruning.duration', $duration * 1000, [
            'aggregate_id' => $result->getAggregateId()
        ]);

        $this->collector->gauge('snapshot.pruning.removed', $result->getSnapshotsRemoved(), [
            'aggregate_id' => $result->getAggregateId()
        ]);

        $this->collector->increment('snapshot.pruning.completed');
    }

    public function recordPruningFailure(string $aggregateId, \Exception $error): void
    {
        $this->collector->increment('snapshot.pruning.failed', [
            'aggregate_id' => $aggregateId,
            'error_type' => get_class($error)
        ]);
    }
}

class PruningException extends \Exception {}

