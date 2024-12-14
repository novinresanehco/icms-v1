<?php

namespace App\Core\Audit;

class AuditArchivalManager
{
    private ArchiveRepository $repository;
    private StorageManager $storage;
    private RetentionManager $retention;
    private CompressionService $compression;
    private EncryptionService $encryption;
    private JobDispatcher $dispatcher;
    private LoggerInterface $logger;

    public function __construct(
        ArchiveRepository $repository,
        StorageManager $storage,
        RetentionManager $retention,
        CompressionService $compression,
        EncryptionService $encryption,
        JobDispatcher $dispatcher,
        LoggerInterface $logger
    ) {
        $this->repository = $repository;
        $this->storage = $storage;
        $this->retention = $retention;
        $this->compression = $compression;
        $this->encryption = $encryption;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    public function archive(ArchiveRequest $request): ArchiveResult
    {
        try {
            // Validate request
            $this->validateRequest($request);

            // Check size and determine strategy
            if ($this->shouldProcessAsync($request)) {
                return $this->processAsyncArchival($request);
            }

            // Process synchronous archival
            return $this->processSyncArchival($request);

        } catch (\Exception $e) {
            $this->handleArchivalError($e, $request);
            throw new ArchivalException(
                "Archival failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function restore(RestoreRequest $request): RestoreResult
    {
        try {
            // Validate restore request
            $this->validateRestoreRequest($request);

            // Process restore
            if ($this->shouldProcessAsyncRestore($request)) {
                return $this->processAsyncRestore($request);
            }

            return $this->processSyncRestore($request);

        } catch (\Exception $e) {
            $this->handleRestoreError($e, $request);
            throw new RestoreException(
                "Restore failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    protected function processSyncArchival(ArchiveRequest $request): ArchiveResult
    {
        // Start transaction
        DB::beginTransaction();

        try {
            // Fetch data to archive
            $data = $this->fetchDataToArchive($request);

            // Create archive
            $archive = $this->createArchive($data, $request);

            // Process archive file
            $file = $this->processArchiveFile($archive, $request);

            // Store archive
            $storagePath = $this->storeArchive($file, $archive);

            // Update archive record
            $archive->setStoragePath($storagePath);
            $this->repository->update($archive);

            // Clean up original data if needed
            if ($request->shouldCleanup()) {
                $this->cleanupOriginalData($data, $request);
            }

            // Commit transaction
            DB::commit();

            return new ArchiveResult(
                true,
                $archive->getId(),
                $this->generateArchiveMetadata($archive, $data)
            );

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function processAsyncArchival(ArchiveRequest $request): ArchiveResult
    {
        // Create archive job
        $job = new ArchiveJob($request);

        // Dispatch job
        $jobId = $this->dispatcher->dispatch($job);

        return new ArchiveResult(
            false,
            null,
            ['job_id' => $jobId],
            ArchiveStatus::PROCESSING
        );
    }

    protected function processSyncRestore(RestoreRequest $request): RestoreResult
    {
        // Start transaction
        DB::beginTransaction();

        try {
            // Fetch archive
            $archive = $this->repository->find($request->getArchiveId());

            // Validate archive status
            $this->validateArchiveStatus($archive);

            // Retrieve archive file
            $file = $this->retrieveArchiveFile($archive);

            // Process restore
            $restoredData = $this->processRestore($file, $archive, $request);

            // Store restored data
            $this->storeRestoredData($restoredData, $request);

            // Update archive status
            $archive->setStatus(ArchiveStatus::RESTORED);
            $this->repository->update($archive);

            // Commit transaction
            DB::commit();

            return new RestoreResult(
                true,
                $archive->getId(),
                $this->generateRestoreMetadata($archive, $restoredData)
            );

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function createArchive(array $data, ArchiveRequest $request): Archive
    {
        return new Archive([
            'id' => Str::uuid(),
            'type' => $request->getType(),
            'status' => ArchiveStatus::PROCESSING,
            'metadata' => [
                'record_count' => count($data),
                'date_range' => $this->getDateRange($data),
                'filters' => $request->getFilters(),
                'compression' => $request->getCompressionType(),
                'encryption' => $request->shouldEncrypt()
            ],
            'created_at' => now()
        ]);
    }

    protected function processArchiveFile(Archive $archive, ArchiveRequest $request): File
    {
        $file = new File(
            tempnam(sys_get_temp_dir(), 'audit_archive_'),
            'archive'
        );

        // Write data
        $file->write(serialize($archive->getData()));

        // Compress
        if ($request->shouldCompress()) {
            $file = $this->compression->compress($file, $request->getCompressionType());
        }

        // Encrypt
        if ($request->shouldEncrypt()) {
            $file = $this->encryption->encrypt($file);
        }

        return $file;
    }

    protected function validateArchiveStatus(Archive $archive): void
    {
        if ($archive->getStatus() === ArchiveStatus::PROCESSING) {
            throw new ArchiveNotReadyException("Archive is still processing");
        }

        if ($archive->getStatus() === ArchiveStatus::CORRUPTED) {
            throw new CorruptedArchiveException("Archive is corrupted");
        }
    }

    protected function shouldProcessAsync(ArchiveRequest $request): bool
    {
        return $request->getEstimatedSize() > config('audit.archive.async_threshold')
            || $request->preferAsync();
    }

    protected function shouldProcessAsyncRestore(RestoreRequest $request): bool
    {
        $archive = $this->repository->find($request->getArchiveId());
        return $archive->getSize() > config('audit.restore.async_threshold')
            || $request->preferAsync();
    }

    protected function cleanupOriginalData(array $data, ArchiveRequest $request): void
    {
        if ($request->shouldSoftDelete()) {
            $this->softDeleteData($data);
        } else {
            $this->hardDeleteData($data);
        }
    }

    protected function generateArchiveMetadata(Archive $archive, array $data): array
    {
        return [
            'archive_id' => $archive->getId(),
            'record_count' => count($data),
            'date_range' => $this->getDateRange($data),
            'size' => $archive->getSize(),
            'compression' => $archive->getCompressionType(),
            'encrypted' => $archive->isEncrypted(),
            'timestamp' => now()
        ];
    }
}
