<?php

namespace App\Core\Events\Snapshots\Consistency;

class ConsistencyVerifier
{
    private HashGenerator $hashGenerator;
    private StateComparator $comparator;
    private ConsistencyLogger $logger;
    private EventStore $eventStore;

    public function __construct(
        HashGenerator $hashGenerator,
        StateComparator $comparator,
        ConsistencyLogger $logger,
        EventStore $eventStore
    ) {
        $this->hashGenerator = $hashGenerator;
        $this->comparator = $comparator;
        $this->logger = $logger;
        $this->eventStore = $eventStore;
    }

    public function verifySnapshot(Snapshot $snapshot): VerificationResult
    {
        $this->logger->startVerification($snapshot);
        
        try {
            // Generate hash of snapshot state
            $snapshotHash = $this->hashGenerator->generateHash($snapshot->getState());
            
            // Rebuild aggregate from events
            $events = $this->eventStore->getEventsForAggregate(
                $snapshot->getAggregateId(),
                $snapshot->getVersion()
            );
            
            $rebuiltAggregate = $this->rebuildAggregate($events);
            $rebuiltHash = $this->hashGenerator->generateHash($rebuiltAggregate->getState());

            // Compare hashes
            $isConsistent = $snapshotHash === $rebuiltHash;
            
            $result = new VerificationResult(
                $snapshot,
                $isConsistent,
                $snapshotHash,
                $rebuiltHash
            );

            $this->logger->completeVerification($result);
            return $result;

        } catch (\Exception $e) {
            $this->logger->verificationError($snapshot, $e);
            throw new ConsistencyVerificationException(
                "Verification failed: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    private function rebuildAggregate(array $events): AggregateRoot
    {
        $aggregate = new AggregateRoot();
        foreach ($events as $event) {
            $aggregate->apply($event);
        }
        return $aggregate;
    }
}

class HashGenerator
{
    private string $algorithm;
    private array $options;

    public function __construct(string $algorithm = 'sha256', array $options = [])
    {
        $this->algorithm = $algorithm;
        $this->options = $options;
    }

    public function generateHash($data): string
    {
        $normalized = $this->normalizeData($data);
        return hash($this->algorithm, $normalized);
    }

    private function normalizeData($data): string
    {
        if (is_object($data)) {
            return $this->normalizeObject($data);
        }

        if (is_array($data)) {
            return $this->normalizeArray($data);
        }

        return (string) $data;
    }

    private function normalizeObject($object): string
    {
        if (method_exists($object, 'getHashableState')) {
            return $this->normalizeData($object->getHashableState());
        }

        return $this->normalizeArray(get_object_vars($object));
    }

    private function normalizeArray(array $array): string
    {
        ksort($array);
        
        $normalized = [];
        foreach ($array as $key => $value) {
            $normalized[$key] = $this->normalizeData($value);
        }

        return json_encode($normalized);
    }
}

class StateComparator
{
    public function compare($state1, $state2): ComparisonResult
    {
        $differences = [];
        $this->findDifferences($state1, $state2, '', $differences);
        
        return new ComparisonResult(
            empty($differences),
            $differences
        );
    }

    private function findDifferences($value1, $value2, string $path, array &$differences): void
    {
        if (is_array($value1) && is_array($value2)) {
            $this->compareArrays($value1, $value2, $path, $differences);
            return;
        }

        if (is_object($value1) && is_object($value2)) {
            $this->compareObjects($value1, $value2, $path, $differences);
            return;
        }

        if ($value1 !== $value2) {
            $differences[] = new Difference(
                $path,
                $value1,
                $value2
            );
        }
    }

    private function compareArrays(array $array1, array $array2, string $path, array &$differences): void
    {
        $allKeys = array_unique(array_merge(array_keys($array1), array_keys($array2)));
        
        foreach ($allKeys as $key) {
            $currentPath = $path ? "{$path}.{$key}" : $key;
            
            if (!array_key_exists($key, $array1)) {
                $differences[] = new Difference(
                    $currentPath,
                    null,
                    $array2[$key],
                    DifferenceType::MISSING_KEY
                );
                continue;
            }

            if (!array_key_exists($key, $array2)) {
                $differences[] = new Difference(
                    $currentPath,
                    $array1[$key],
                    null,
                    DifferenceType::EXTRA_KEY
                );
                continue;
            }

            $this->findDifferences(
                $array1[$key],
                $array2[$key],
                $currentPath,
                $differences
            );
        }
    }

    private function compareObjects($obj1, $obj2, string $path, array &$differences): void
    {
        $this->compareArrays(
            get_object_vars($obj1),
            get_object_vars($obj2),
            $path,
            $differences
        );
    }
}

class VerificationResult
{
    private Snapshot $snapshot;
    private bool $isConsistent;
    private string $snapshotHash;
    private string $rebuiltHash;
    private array $details;

    public function __construct(
        Snapshot $snapshot,
        bool $isConsistent,
        string $snapshotHash,
        string $rebuiltHash,
        array $details = []
    ) {
        $this->snapshot = $snapshot;
        $this->isConsistent = $isConsistent;
        $this->snapshotHash = $snapshotHash;
        $this->rebuiltHash = $rebuiltHash;
        $this->details = $details;
    }

    public function isConsistent(): bool
    {
        return $this->isConsistent;
    }

    public function getSnapshot(): Snapshot
    {
        return $this->snapshot;
    }

    public function getSnapshotHash(): string
    {
        return $this->snapshotHash;
    }

    public function getRebuiltHash(): string
    {
        return $this->rebuiltHash;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}

class Difference
{
    private string $path;
    private $expectedValue;
    private $actualValue;
    private string $type;

    public function __construct(
        string $path,
        $expectedValue,
        $actualValue,
        string $type = DifferenceType::VALUE_MISMATCH
    ) {
        $this->path = $path;
        $this->expectedValue = $expectedValue;
        $this->actualValue = $actualValue;
        $this->type = $type;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getExpectedValue()
    {
        return $this->expectedValue;
    }

    public function getActualValue()
    {
        return $this->actualValue;
    }

    public function getType(): string
    {
        return $this->type;
    }
}

class DifferenceType
{
    public const VALUE_MISMATCH = 'value_mismatch';
    public const MISSING_KEY = 'missing_key';
    public const EXTRA_KEY = 'extra_key';
    public const TYPE_MISMATCH = 'type_mismatch';
}

class ConsistencyLogger
{
    private LoggerInterface $logger;
    private MetricsCollector $metrics;

    public function __construct(LoggerInterface $logger, MetricsCollector $metrics)
    {
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function startVerification(Snapshot $snapshot): void
    {
        $this->logger->info('Starting snapshot verification', [
            'aggregate_id' => $snapshot->getAggregateId(),
            'version' => $snapshot->getVersion()
        ]);

        $this->metrics->increment('snapshot.verification.started');
    }

    public function completeVerification(VerificationResult $result): void
    {
        $this->logger->info('Verification completed', [
            'aggregate_id' => $result->getSnapshot()->getAggregateId(),
            'consistent' => $result->isConsistent(),
            'snapshot_hash' => $result->getSnapshotHash(),
            'rebuilt_hash' => $result->getRebuiltHash()
        ]);

        $this->metrics->increment(
            'snapshot.verification.completed',
            ['consistent' => $result->isConsistent()]
        );
    }

    public function verificationError(Snapshot $snapshot, \Exception $e): void
    {
        $this->logger->error('Verification failed', [
            'aggregate_id' => $snapshot->getAggregateId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->increment('snapshot.verification.failed');
    }
}

class ConsistencyVerificationException extends \Exception {}

