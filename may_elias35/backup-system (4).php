// File: app/Core/Backup/Manager/BackupManager.php
<?php

namespace App\Core\Backup\Manager;

class BackupManager
{
    protected BackupRepository $repository;
    protected StorageManager $storage;
    protected BackupQueue $queue;
    protected CompressManager $compressor;

    public function create(array $options = []): Backup
    {
        DB::beginTransaction();
        try {
            // Create backup record
            $backup = $this->repository->create([
                'type' => $options['type'] ?? 'full',
                'status' => BackupStatus::PENDING,
                'metadata' => $options
            ]);

            // Queue backup job
            $this->queue->push(new CreateBackupJob($backup));

            DB::commit();
            return $backup;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BackupException("Failed to create backup: " . $e->getMessage());
        }
    }

    public function restore(Backup $backup): void
    {
        if (!$backup->isValid()) {
            throw new BackupException("Invalid backup");
        }

        DB::beginTransaction();
        try {
            $backup->setStatus(BackupStatus::RESTORING);
            $this->repository->save($backup);

            // Queue restore job
            $this->queue->push(new RestoreBackupJob($backup));

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BackupException("Failed to initiate restore: " . $e->getMessage());
        }
    }
}

// File: app/Core/Backup/Storage/StorageManager.php
<?php

namespace App\Core\Backup\Storage;

class StorageManager
{
    protected StorageProvider $provider;
    protected PathGenerator $pathGenerator;
    protected EncryptionManager $encryption;
    protected StorageConfig $config;

    public function store(Backup $backup, string $content): void
    {
        $path = $this->pathGenerator->generate($backup);
        $encrypted = $this->encryption->encrypt($content);

        try {
            $this->provider->put($path, $encrypted, [
                'visibility' => 'private',
                'metadata' => $backup->getMetadata()
            ]);

            $backup->setPath($path);
            $backup->setSize(strlen($content));
        } catch (\Exception $e) {
            throw new StorageException("Failed to store backup: " . $e->getMessage());
        }
    }

    public function retrieve(Backup $backup): string
    {
        try {
            $content = $this->provider->get($backup->getPath());
            return $this->encryption->decrypt($content);
        } catch (\Exception $e) {
            throw new StorageException("Failed to retrieve backup: " . $e->getMessage());
        }
    }
}

// File: app/Core/Backup/Jobs/CreateBackupJob.php
<?php

namespace App\Core\Backup\Jobs;

class CreateBackupJob implements ShouldQueue
{
    protected Backup $backup;
    protected DataCollector $collector;
    protected CompressManager $compressor;
    protected StorageManager $storage;

    public function handle(): void
    {
        try {
            // Collect data
            $data = $this->collector->collect($this->backup->getType());
            
            // Compress data
            $compressed = $this->compressor->compress($data);
            
            // Store backup
            $this->storage->store($this->backup, $compressed);
            
            // Update status
            $this->backup->setStatus(BackupStatus::COMPLETED);
            $this->backup->save();
            
        } catch (\Exception $e) {
            $this->handleFailure($e);
        }
    }

    protected function handleFailure(\Exception $e): void
    {
        $this->backup->setStatus(BackupStatus::FAILED);
        $this->backup->setError($e->getMessage());
        $this->backup->save();
        
        throw $e;
    }
}

// File: app/Core/Backup/Verification/BackupVerifier.php
<?php

namespace App\Core\Backup\Verification;

class BackupVerifier
{
    protected DataVerifier $dataVerifier;
    protected IntegrityChecker $integrityChecker;
    protected ValidationConfig $config;

    public function verify(Backup $backup): VerificationResult
    {
        try {
            // Check integrity
            $this->checkIntegrity($backup);
            
            // Verify data
            $this->verifyData($backup);
            
            // Update verification status
            $backup->setVerified(true);
            $backup->setVerifiedAt(now());
            $backup->save();
            
            return new VerificationResult(true);
        } catch (\Exception $e) {
            return new VerificationResult(false, $e->getMessage());
        }
    }

    protected function checkIntegrity(Backup $backup): void
    {
        if (!$this->integrityChecker->check($backup)) {
            throw new VerificationException("Backup integrity check failed");
        }
    }

    protected function verifyData(Backup $backup): void
    {
        if (!$this->dataVerifier->verify($backup)) {
            throw new VerificationException("Backup data verification failed");
        }
    }
}
