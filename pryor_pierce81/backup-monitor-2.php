<?php

namespace App\Core\Monitoring\Backup;

class BackupMonitor
{
    private BackupRegistry $registry;
    private BackupValidator $validator;
    private ConsistencyChecker $consistencyChecker;
    private StorageAnalyzer $storageAnalyzer;
    private AlertManager $alertManager;

    public function monitor(): BackupStatus
    {
        $backups = [];
        $validations = [];
        $consistency = [];
        $storage = $this->storageAnalyzer->analyze();

        foreach ($this->registry->getBackups() as $backup) {
            $backupValidation = $this->validator->validate($backup);
            $backupConsistency = $this->consistencyChecker->check($backup);
            
            if ($backupValidation->hasIssues() || $backupConsistency->hasIssues()) {
                $this->alertManager->notify(new BackupAlert($backup, $backupValidation, $backupConsistency));
            }

            $backups[] = $backup;
            $validations[$backup->getId()] = $backupValidation;
            $consistency[$backup->getId()] = $backupConsistency;
        }

        return new BackupStatus($backups, $validations, $consistency, $storage);
    }
}

class BackupValidator
{
    private IntegrityChecker $integrityChecker;
    private EncryptionVerifier $encryptionVerifier;
    private CompletenessChecker $completenessChecker;

    public function validate(Backup $backup): ValidationResult
    {
        $issues = [];

        try {
            if (!$this->integrityChecker->verify($backup)) {
                $issues[] = new ValidationIssue('integrity', 'Backup integrity check failed');
            }

            if (!$this->encryptionVerifier->verify($backup)) {
                $issues[] = new ValidationIssue('encryption', 'Backup encryption verification failed');
            }

            $completeness = $this->completenessChecker->check($backup);
            if (!$completeness->isComplete()) {
                $issues[] = new ValidationIssue('completeness', 'Backup is incomplete');
            }
        } catch (\Exception $e) {
            $issues[] = new ValidationIssue('validation', $e->getMessage());
        }

        return new ValidationResult($issues);
    }
}

class ConsistencyChecker
{
    private DataVerifier $dataVerifier;
    private MetadataChecker $metadataChecker;
    private VersionChecker $versionChecker;

    public function check(Backup $backup): ConsistencyResult
    {
        $issues = [];

        try {
            if (!$this->dataVerifier->verify($backup)) {
                $issues[] = new ConsistencyIssue('data', 'Data verification failed');
            }

            $metadataResult = $this->metadataChecker->check($backup);
            if (!$metadataResult->isValid()) {
                $issues[] = new ConsistencyIssue('metadata', 'Metadata inconsistency detected');
            }

            if (!$this->versionChecker->verify($backup)) {
                $issues[] = new ConsistencyIssue('version', 'Version inconsistency detected');
            }
        } catch (\Exception $e) {
            $issues[] = new ConsistencyIssue('check', $e->getMessage());
        }

        return new ConsistencyResult($issues);
    }
}

class StorageAnalyzer
{
    private SpaceChecker $spaceChecker;
    private RetentionChecker $retentionChecker;
    private PerformanceAnalyzer $performanceAnalyzer;

    public function analyze(): StorageAnalysis
    {
        $spaceUsage = $this->spaceChecker->check();
        $retention = $this->retentionChecker->check();
        $performance = $this->performanceAnalyzer->analyze();

        return new StorageAnalysis($spaceUsage, $retention, $performance);
    }
}

class BackupStatus
{
    private array $backups;
    private array $validations;
    private array $consistency;
    private StorageAnalysis $storage;
    private float $timestamp;

    public function __construct(
        array $backups,
        array $validations,
        array $consistency,
        StorageAnalysis $storage
    ) {
        $this->backups = $backups;
        $this->validations = $validations;
        $this->consistency = $consistency;
        $this->storage = $storage;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return $this->hasValidationIssues() ||
               $this->hasConsistencyIssues() ||
               $this->storage->hasIssues();
    }

    private function hasValidationIssues(): bool
    {
        foreach ($this->validations as $validation) {
            if ($validation->hasIssues()) {
                return true;
            }
        }
        return false;
    }

    private function hasConsistencyIssues(): bool
    {
        foreach ($this->consistency as $check) {
            if ($check->hasIssues()) {
                return true;
            }
        }
        return false;
    }
}

class ValidationResult
{
    private array $issues;
    private float $timestamp;

    public function __construct(array $issues)
    {
        $this->issues = $issues;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return !empty($this->issues);
    }

    public function getIssues(): array
    {
        return $this->issues;
    }
}

class ConsistencyResult
{
    private array $issues;
    private float $timestamp;

    public function __construct(array $issues)
    {
        $this->issues = $issues;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return !empty($this->issues);
    }

    public function getIssues(): array
    {
        return $this->issues;
    }
}

class StorageAnalysis
{
    private array $spaceUsage;
    private array $retention;
    private array $performance;
    private float $timestamp;

    public function __construct(array $spaceUsage, array $retention, array $performance)
    {
        $this->spaceUsage = $spaceUsage;
        $this->retention = $retention;
        $this->performance = $performance;
        $this->timestamp = microtime(true);
    }

    public function hasIssues(): bool
    {
        return $this->spaceUsage['percentage'] > 90 ||
               !$this->retention['compliant'] ||
               $this->performance['degraded'];
    }
}