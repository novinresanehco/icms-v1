<?php

namespace App\Core\Events\Snapshots\Maintenance;

class SnapshotMaintenanceManager
{
    private SnapshotStore $store;
    private MaintenanceStrategy $strategy;
    private MaintenanceLogger $logger;

    public function __construct(
        SnapshotStore $store,
        MaintenanceStrategy $strategy,
        MaintenanceLogger $logger
    ) {
        $this->store = $store;
        $this->strategy = $strategy;
        $this->logger = $logger;
    }

    public function cleanup(MaintenanceOptions $options): MaintenanceResult
    {
        $this->logger->startMaintenance($options);
        $result = new MaintenanceResult();

        try {
            $snapshots = $this->store->findSnapshotsForCleanup($options);
            
            foreach ($snapshots as $snapshot) {
                if ($this->strategy->shouldRemove($snapshot)) {
                    $this->store->delete($snapshot->getAggregateId(), $snapshot->getVersion());
                    $result->addRemoved($snapshot);
                }
            }

            $this->logger->completeMaintenance($result);
            return $result;

        } catch (\Exception $e) {
            $this->logger->maintenanceError($e);
            throw $e;
        }
    }

    public function optimize(string $aggregateId): void
    {
        $snapshots = $this->store->getSnapshots($aggregateId);
        $optimizedSnapshots = $this->strategy->optimizeSnapshots($snapshots);
        
        foreach ($snapshots as $snapshot) {
            if (!in_array($snapshot, $optimizedSnapshots)) {
                $this->store->delete($snapshot->getAggregateId(), $snapshot->getVersion());
            }
        }
    }
}

class MaintenanceOptions
{
    private ?\DateTimeInterface $before;
    private ?int $maxVersionsToKeep;
    private bool $dryRun;

    public function __construct()
    {
        $this->dryRun = false;
    }

    public function setBefore(\DateTimeInterface $before): self
    {
        $this->before = $before;
        return $this;
    }

    public function setMaxVersionsToKeep(int $versions): self
    {
        $this->maxVersionsToKeep = $versions;
        return $this;
    }

    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    public function getBefore(): ?\DateTimeInterface
    {
        return $this->before;
    }

    public function getMaxVersionsToKeep(): ?int
    {
        return $this->maxVersionsToKeep;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }
}

class MaintenanceResult
{
    private array $removedSnapshots = [];
    private \DateTimeImmutable $startTime;
    private ?\DateTimeImmutable $endTime = null;

    public function __construct()
    {
        $this->startTime = new \DateTimeImmutable();
    }

    public function addRemoved(Snapshot $snapshot): void
    {
        $this->removedSnapshots[] = $snapshot;
    }

    public function complete(): void
    {
        $this->endTime = new \DateTimeImmutable();
    }

    public function getRemovedCount(): int
    {
        return count($this->removedSnapshots);
    }

    public function getDuration(): float
    {
        if (!$this->endTime) {
            return 0;
        }

        return $this->endTime->getTimestamp() - $this->startTime->getTimestamp();
    }

    public function getRemovedSnapshots(): array
    {
        return $this->removedSnapshots;
    }
}

interface MaintenanceStrategy
{
    public function shouldRemove(Snapshot $snapshot): bool;
    public function optimizeSnapshots(array $snapshots): array;
}

class DefaultMaintenanceStrategy implements MaintenanceStrategy
{
    private int $maxAge;
    private int $maxVersions;

    public function __construct(int $maxAge = 30, int $maxVersions = 5)
    {
        $this->maxAge = $maxAge;
        $this->maxVersions = $maxVersions;
    }

    public function shouldRemove(Snapshot $snapshot): bool
    {
        $age = time() - $snapshot->getCreatedAt()->getTimestamp();
        return $age > ($this->maxAge * 86400);
    }

    public function optimizeSnapshots(array $snapshots): array
    {
        usort($snapshots, fn($a, $b) => $b->getVersion() - $a->getVersion());
        return array_slice($snapshots, 0, $this->maxVersions);
    }
}

class MaintenanceLogger
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function startMaintenance(MaintenanceOptions $options): void
    {
        $this->logger->info('Starting snapshot maintenance', [
            'before' => $options->getBefore()?->format('Y-m-d H:i:s'),
            'max_versions' => $options->getMaxVersionsToKeep(),
            'dry_run' => $options->isDryRun()
        ]);
    }

    public function completeMaintenance(MaintenanceResult $result): void
    {
        $this->logger->info('Snapshot maintenance completed', [
            'removed_count' => $result->getRemovedCount(),
            'duration' => $result->getDuration()
        ]);
    }

    public function maintenanceError(\Exception $e): void
    {
        $this->logger->error('Snapshot maintenance error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
