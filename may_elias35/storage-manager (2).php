<?php

namespace App\Core\Audit;

class AuditStorageManager
{
    private StorageProvider $provider;
    private ArchiveManager $archiveManager;
    private CompressionService $compressionService;
    private EncryptionService $encryptionService;
    private RetentionManager $retentionManager;
    private MetricsCollector $metrics;

    public function __construct(
        StorageProvider $provider,
        ArchiveManager $archiveManager,
        CompressionService $compressionService,
        EncryptionService $encryptionService,
        RetentionManager $retentionManager,
        MetricsCollector $metrics
    ) {
        $this->provider = $provider;
        $this->archiveManager = $archiveManager;
        $this->compressionService = $compressionService;
        $this->encryptionService = $encryptionService;
        $this->retentionManager = $retentionManager;
        $this->metrics = $metrics;
    }

    public function store(AuditData $data): string
    {
        $startTime = microtime(true);
        
        try {
            // Validate storage requirements
            $this->validateStorageRequirements($data);

            // Prepare data for storage
            $preparedData = $this->prepareForStorage($data);

            // Generate storage key
            $key = $this->generateStorageKey($data);

            // Store data
            $this->provider->store($key, $preparedData, [
                'metadata' => $this->generateMetadata($data),
                'tags' => $this->generateTags($data),
                'retention' => $this->calculateRetention($data)
            ]);

            // Record metrics
            $this->recordStorageMetrics($data, microtime(true) - $startTime);

            return $key;

        } catch (\Exception $e) {
            $this->handleStorageError($e, $data);
            throw $e;
        }
    }

    public function retrieve(string $key): AuditData
    {
        try {
            // Get data from storage
            $storedData = $this->provider->retrieve($key);

            // Validate data integrity
            $this->validateDataIntegrity($storedData);

            // Process stored data
            return $this->processStoredData($storedData);

        } catch (\Exception $e) {
            $this->handleRetrievalError($e, $key);
            throw $e;
        }
    }

    public function archive(array $criteria): ArchiveResult
    {
        try {
            // Identify data for archiving
            $dataToArchive = $this->identifyDataForArchive($criteria);

            // Create archive
            $archiveId = $this->archiveManager->createArchive($dataToArchive);

            // Update storage status
            $this->updateStorageStatus($dataToArchive, 'archived');

            // Record archive metrics
            $this->recordArchiveMetrics($dataToArchive);

            return new ArchiveResult($archiveId, count($dataToArchive));

        } catch (\Exception $e) {
            $this->handleArchiveError($e, $criteria);
            throw $e;
        }
    }

    public function applyRetentionPolicy(): RetentionResult
    {
        try {
            // Get expired data
            $expiredData = $this->retentionManager->getExpiredData();

            // Archive if needed
            if ($this->shouldArchiveBeforeDelete($expiredData)) {
                $this->archiveExpiredData($expiredData);
            }

            // Delete expired data
            $deletedCount = $this->deleteExpiredData($expiredData);

            // Record retention metrics
            $this->recordRetentionMetrics($expiredData, $deletedCount);

            return new RetentionResult($deletedCount, count($expiredData));

        } catch (\Exception $e) {
            $this->handleRetentionError($e);
            throw $e;
        }
    }

    protected function prepareForStorage(AuditData $data): string
    {
        // Serialize data
        $serialized = $this->serializeData($data);

        // Compress if needed
        if ($this->shouldCompress($data)) {
            $serialized = $this->compressionService->compress($serialized);
        }

        // Encrypt data
        return $this->encryptionService->encrypt($serialized);
    }

    protected function processStoredData(string $storedData): AuditData
    {
        // Decrypt data
        $decrypted = $this->encryptionService->decrypt($storedData);

        // Decompress if needed
        if ($this->isCompressed($decrypted)) {
            $decrypted = $this->compressionService->decompress($decrypted);
        }

        // Unserialize data
        return $this->unserializeData($decrypted);
    }

    protected function validateStorageRequirements(AuditData $data): void
    {
        // Check size limits
        if ($this->exceedsSizeLimit($data)) {
            throw new StorageLimitException("Data exceeds size limit");
        }

        // Validate data format
        if (!$this->isValidFormat($data)) {
            throw new InvalidDataFormatException("Invalid data format");
        }

        // Check storage capacity
        if (!$this->hasStorageCapacity($data)) {
            throw new InsufficientStorageException("Insufficient storage capacity");
        }
    }

    protected function generateStorageKey(AuditData $data): string
    {
        return sprintf(
            '%s/%s/%s',
            $data->getType(),
            date('Y/m/d'),
            Str::uuid()
        );
    }

    protected function generateMetadata(AuditData $data): array
    {
        return [
            'type' => $data->getType(),
            'size' => $data->getSize(),
            'created_at' => now(),
            'checksum' => $this->calculateChecksum($data),
            'encryption' => $this->encryptionService->getMetadata(),
            'compression' => $this->compressionService->getMetadata(),
            'retention_policy' => $this->retentionManager->getPolicy($data)
        ];
    }

    protected function recordStorageMetrics(AuditData $data, float $duration): void
    {
        $this->metrics->record([
            'storage_operation_duration' => $duration,
            'stored_data_size' => $data->getSize(),
            'storage_compression_ratio' => $this->calculateCompressionRatio($data),
            'storage_operation_success' => 1
        ]);
    }

    protected function shouldArchiveBeforeDelete(array $expiredData): bool
    {
        foreach ($expiredData as $data) {
            if ($data->requiresArchiving()) {
                return true;
            }
        }
        return false;
    }
}
