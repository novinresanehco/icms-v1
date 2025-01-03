<?php

namespace App\Core\Backup;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\MetricsCollector;
use App\Core\Validation\ValidationService;
use App\Core\Logging\AuditLogger;
use App\Core\Exceptions\BackupException;

/**
 * Critical system backup manager with comprehensive protection and monitoring
 */
class BackupManager implements BackupInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private AuditLogger $logger;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        MetricsCollector $metrics,
        AuditLogger $logger,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Creates a system backup with full validation and monitoring
     * @throws BackupException If backup creation fails
     */
    public function createBackup(string $type = 'full'): BackupResult 
    {
        // Start metrics collection
        $startTime = microtime(true);
        
        try {
            // Pre-backup validation
            $this->validateBackupRequest($type);
            
            // Create backup point for rollback
            $backupPoint = $this->createBackupPoint();
            
            // Start backup monitoring
            $monitoringId = $this->startBackupMonitoring($type);
            
            DB::beginTransaction();
            
            try {
                // Execute backup with monitoring
                $result = $this->executeBackup($type);
                
                // Validate backup integrity
                $this->validateBackupIntegrity($result);
                
                // Commit transaction
                DB::commit();
                
                // Log successful backup
                $this->logBackupSuccess($type, $result);
                
                return $result;
                
            } catch (\Exception $e) {
                // Rollback on failure
                DB::rollback();
                
                // Restore from backup point
                $this->restoreFromPoint($backupPoint);
                
                throw $e;
            }
            
        } catch (\Exception $e) {
            // Handle backup failure
            $this->handleBackupFailure($e, $type);
            
            throw new BackupException(
                'Backup creation failed: ' . $e->getMessage(),
                previous: $e
            );
            
        } finally {
            // Record metrics
            $this->recordBackupMetrics($startTime, $type);
            
            // Cleanup temporary resources
            $this->cleanup($backupPoint ?? null, $monitoringId ?? null);
        }
    }

    /**
     * Restores system from backup with validation and monitoring
     * @throws BackupException If restore fails
     */
    public function restoreFromBackup(string $backupId): RestoreResult
    {
        $startTime = microtime(true);
        
        try {
            // Validate restore request 
            $this->validateRestoreRequest($backupId);
            
            // Create restore point
            $restorePoint = $this->createRestorePoint();
            
            // Start restore monitoring
            $monitoringId = $this->startRestoreMonitoring($backupId);
            
            DB::beginTransaction();
            
            try {
                // Execute restore with monitoring
                $result = $this->executeRestore($backupId);
                
                // Validate restored state
                $this->validateRestoredState($result);
                
                DB::commit();
                
                // Log successful restore
                $this->logRestoreSuccess($backupId, $result);
                
                return $result;
                
            } catch (\Exception $e) {
                DB::rollback();
                $this->restoreFromPoint($restorePoint);
                throw $e;
            }
            
        } catch (\Exception $e) {
            $this->handleRestoreFailure($e, $backupId);
            throw new BackupException(
                'Restore failed: ' . $e->getMessage(),
                previous: $e
            );
            
        } finally {
            $this->recordRestoreMetrics($startTime, $backupId);
            $this->cleanup($restorePoint ?? null, $monitoringId ?? null);
        }
    }

    /**
     * Validates backup exists and is healthy
     * @throws BackupException If validation fails
     */
    public function validateBackup(string $backupId): ValidationResult
    {
        try {
            // Security validation
            $this->security->validateAccess('backup.validate');
            
            // Check backup exists
            if (!$this->backupExists($backupId)) {
                throw new BackupException("Backup not found: {$backupId}");
            }
            
            // Validate backup integrity
            $integrityResult = $this->validateBackupIntegrity($backupId);
            
            // Validate backup contents
            $contentResult = $this->validateBackupContents($backupId);
            
            return new ValidationResult([
                'integrity' => $integrityResult,
                'content' => $contentResult
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Backup validation failed', [
                'backup_id' => $backupId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function validateBackupRequest(string $type): void
    {
        // Validate backup type
        if (!in_array($type, $this->config['allowed_types'])) {
            throw new BackupException("Invalid backup type: {$type}");
        }
        
        // Check system state allows backup
        if (!$this->validator->verifySystemState()) {
            throw new BackupException('System state invalid for backup');
        }
        
        // Validate security requirements
        $this->security->validateAccess('backup.create');
    }

    protected function validateRestoreRequest(string $backupId): void
    {
        // Validate backup exists
        if (!$this->backupExists($backupId)) {
            throw new BackupException("Backup not found: {$backupId}");
        }
        
        // Validate backup integrity
        if (!$this->validateBackupIntegrity($backupId)) {
            throw new BackupException("Backup integrity check failed");
        }
        
        // Verify system state allows restore
        if (!$this->validator->verifySystemState()) {
            throw new BackupException('System state invalid for restore');
        }
        
        // Validate security access
        $this->security->validateAccess('backup.restore');
    }

    protected function executeBackup(string $type): BackupResult
    {
        // Create backup with monitoring
        $monitor = new BackupMonitor($type);
        
        return $monitor->execute(function() use ($type) {
            return match($type) {
                'full' => $this->createFullBackup(),
                'incremental' => $this->createIncrementalBackup(),
                'snapshot' => $this->createSnapshotBackup(),
                default => throw new BackupException("Unsupported backup type: {$type}")
            };
        });
    }

    protected function executeRestore(string $backupId): RestoreResult
    {
        $monitor = new RestoreMonitor($backupId);
        
        return $monitor->execute(function() use ($backupId) {
            // Load backup metadata
            $backup = $this->loadBackup($backupId);
            
            // Execute appropriate restore
            return match($backup->type) {
                'full' => $this->restoreFullBackup($backup),
                'incremental' => $this->restoreIncrementalBackup($backup),
                'snapshot' => $this->restoreSnapshotBackup($backup),
                default => throw new BackupException("Unsupported restore type: {$backup->type}")
            };
        });
    }

    protected function handleBackupFailure(\Exception $e, string $type): void
    {
        // Log comprehensive failure details
        $this->logger->error('Backup failed', [
            'type' => $type,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'system_state' => $this->getSystemState()
        ]);

        // Notify administrators
        $this->notifyAdministrators('Backup Failed', $e);

        // Update metrics
        $this->metrics->incrementCounter('backup_failures');
    }

    protected function handleRestoreFailure(\Exception $e, string $backupId): void
    {
        // Log comprehensive failure details
        $this->logger->error('Restore failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'system_state' => $this->getSystemState()
        ]);

        // Notify administrators
        $this->notifyAdministrators('Restore Failed', $e);

        // Update metrics
        $this->metrics->incrementCounter('restore_failures');
    }

    protected function cleanup(?string $point = null, ?string $monitoringId = null): void
    {
        try {
            if ($point) {
                $this->removeBackupPoint($point);
            }
            
            if ($monitoringId) {
                $this->stopMonitoring($monitoringId);
            }
            
        } catch (\Exception $e) {
            // Log but don't throw
            $this->logger->warning('Cleanup failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function recordBackupMetrics(float $startTime, string $type): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record([
            'backup_duration' => $duration,
            'backup_type' => $type,
            'backup_size' => $this->getBackupSize(),
            'system_load' => sys_getloadavg()[0],
            'memory_usage' => memory_get_peak_usage(true)
        ]);
    }

    protected function recordRestoreMetrics(float $startTime, string $backupId): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record([
            'restore_duration' => $duration,
            'backup_id' => $backupId,
            'system_load' => sys_getloadavg()[0],
            'memory_usage' => memory_get_peak_usage(true)
        ]);
    }
}
