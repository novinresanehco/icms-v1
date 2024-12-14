<?php

namespace App\Core\Recovery;

use App\Core\Security\SecurityManager;
use App\Core\Protection\CoreProtectionSystem;
use App\Core\Exceptions\{RecoveryException, BackupException};

class BackupRecoveryManager implements BackupRecoveryInterface
{
    private SecurityManager $security;
    private CoreProtectionSystem $protection;
    private StorageManager $storage;
    private ValidationService $validator;
    private MetricsCollector $metrics;

    public function createBackup(BackupConfig $config, SecurityContext $context): BackupResult
    {
        return $this->protection->executeProtectedOperation(
            function() use ($config, $context) {
                $validatedConfig = $this->validateBackupConfig($config);
                
                $this->verifySystemState();
                $backupPoint = $this->initiateBackup($validatedConfig);
                
                try {
                    $result = $this->executeBackup($backupPoint, $validatedConfig);
                    $this->verifyBackup($result);
                    return $result;
                } catch (\Exception $e) {
                    $this->handleBackupFailure($backupPoint, $e);
                    throw $e;
                }
            },
            $context
        );
    }

    public function restore(string $backupId, RestoreConfig $config, SecurityContext $context): RestoreResult
    {
        return $this->protection->executeProtectedOperation(
            function() use ($backupId, $config, $context) {
                $backup = $this->validateBackup($backupId);
                $validatedConfig = $this->validateRestoreConfig($config);
                
                $this->prepareForRestore($backup, $validatedConfig);
                
                try {
                    $result = $this->executeRestore($backup, $validatedConfig);
                    $this->verifyRestoration($result);
                    return $result;
                } catch (\Exception $e) {
                    $this->handleRestoreFailure($backup, $e);
                    throw $e;
                }
            },
            $context
        );
    }

    public function verifyBackupIntegrity(string $backupId, SecurityContext $context): VerificationResult
    {
        return $this->protection->executeProtectedOperation(
            function() use ($backupId, $context) {
                $backup = $this->loadBackup($backupId);
                return $this->performIntegrityCheck($backup);
            },
            $context
        );
    }

    private function validateBackupConfig(BackupConfig $config): BackupConfig
    {
        if (!$this->validator->validateBackupConfig($config)) {
            throw new BackupException('Invalid backup configuration');
        }

        return $config;
    }

    private function verifySystemState(): void
    {
        if (!$this->isSystemStable()) {
            throw new RecoveryException('System state unsuitable for backup');
        }

        $this->ensureResourceAvailability();
    }

    private function initiateBackup(BackupConfig $config): BackupPoint
    {
        $point = new BackupPoint([
            'timestamp' => now(),
            'config' => $config,
            'checksum' => $this->calculateSystemChecksum()
        ]);

        $this->storage->createBackupPoint($point);
        return $point;
    }

    private function executeBackup(BackupPoint $point, BackupConfig $config): BackupResult
    {
        $this->metrics->startMeasure('backup_execution');

        $data = $this->collectBackupData($config);
        $encrypted = $this->encryptBackupData($data);
        $stored = $this->storeBackupData($point, $encrypted);

        $this->metrics->endMeasure('backup_execution');

        return new BackupResult([
            'backup_id' => $point->getId(),
            'timestamp' => $point->getTimestamp(),
            'size' => $stored->getSize(),
            'checksum' => $stored->getChecksum()
        ]);
    }

    private function verifyBackup(BackupResult $result): void
    {
        $verification = $this->performIntegrityCheck($result->getBackupId());
        
        if (!$verification->isValid()) {
            throw new BackupException('Backup verification failed');
        }
    }

    private function validateRestoreConfig(RestoreConfig $config): RestoreConfig
    {
        if (!$this->validator->validateRestoreConfig($config)) {
            throw new RecoveryException('Invalid restore configuration');
        }

        return $config;
    }

    private function prepareForRestore(Backup $backup, RestoreConfig $config): void
    {
        $this->verifyRestorePrerequisites($backup, $config);
        $this->createRestorePoint();
        $this->stopCriticalServices();
    }

    private function executeRestore(Backup $backup, RestoreConfig $config): RestoreResult
    {
        $this->metrics->startMeasure('restore_execution');

        try {
            $data = $this->loadBackupData($backup);
            $decrypted = $this->decryptBackupData($data);
            $restored = $this->restoreSystemState($decrypted, $config);

            $this->metrics->endMeasure('restore_execution');

            return new RestoreResult([
                'restore_id' => Str::uuid(),
                'timestamp' => now(),
                'status' => 'success',
                'details' => $restored
            ]);
        } finally {
            $this->startCriticalServices();
        }
    }

    private function verifyRestoration(RestoreResult $result): void
    {
        $systemState = $this->verifySystemState();
        
        if (!$systemState->isValid()) {
            throw new RecoveryException('System state invalid after restore');
        }
    }

    private function performIntegrityCheck(Backup $backup): VerificationResult
    {
        return new VerificationResult([
            'checksum_valid' => $this->verifyChecksum($backup),
            'structure_valid' => $this->verifyStructure($backup),
            'encryption_valid' => $this->verifyEncryption($backup),
            'content_valid' => $this->verifyContent($backup)
        ]);
    }

    private function handleBackupFailure(BackupPoint $point, \Exception $e): void
    {
        $this->metrics->recordBackupFailure($point, $e);
        $this->cleanupFailedBackup($point);
    }

    private function handleRestoreFailure(Backup $backup, \Exception $e): void
    {
        $this->metrics->recordRestoreFailure($backup, $e);
        $this->rollbackToLastRestorePoint();
    }
}
