<?php

namespace App\Core\Migration;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Services\AuditService;
use App\Core\Exceptions\MigrationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrationManager
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    private const MAX_RETRY_ATTEMPTS = 3;
    private const BACKUP_RETENTION = 7; // days

    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function migrate(string $migrationId): MigrationResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeMigration($migrationId),
            ['operation' => 'migration_execute', 'migration_id' => $migrationId]
        );
    }

    public function rollback(string $migrationId): RollbackResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRollback($migrationId),
            ['operation' => 'migration_rollback', 'migration_id' => $migrationId]
        );
    }

    public function validateMigration(string $migrationId): ValidationResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeValidation($migrationId),
            ['operation' => 'migration_validate', 'migration_id' => $migrationId]
        );
    }

    private function executeMigration(string $migrationId): MigrationResult
    {
        try {
            // Load migration
            $migration = $this->loadMigration($migrationId);

            // Validate migration
            $this->validateMigrationStructure($migration);

            // Create backup
            $backupId = $this->createBackup($migration);

            // Begin transaction
            DB::beginTransaction();

            try {
                // Execute pre-migration checks
                $this->executeMigrationChecks($migration);

                // Execute migration steps
                $result = $this->executeMigrationSteps($migration);

                // Verify migration success
                $this->verifyMigrationResult($migration, $result);

                // Create migration record
                $this->recordMigration($migration, $result);

                DB::commit();

                return new MigrationResult([
                    'success' => true,
                    'migration_id' => $migrationId,
                    'backup_id' => $backupId,
                    'changes' => $result->changes
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                
                // Attempt recovery
                $this->handleMigrationFailure($e, $migration, $backupId);
                
                throw $e;
            }

        } catch (\Exception $e) {
            throw new MigrationException('Migration failed: ' . $e->getMessage());
        }
    }

    private function executeRollback(string $migrationId): RollbackResult
    {
        try {
            // Load migration record
            $migrationRecord = $this->loadMigrationRecord($migrationId);

            // Verify rollback is possible
            if (!$this->isRollbackPossible($migrationRecord)) {
                throw new MigrationException('Rollback not possible');
            }

            // Create safety backup
            $backupId = $this->createBackup();

            // Begin transaction
            DB::beginTransaction();

            try {
                // Execute rollback steps
                $result = $this->executeRollbackSteps($migrationRecord);

                // Verify rollback success
                $this->verifyRollbackResult($migrationRecord, $result);

                // Update migration record
                $this->updateMigrationRecord($migrationRecord, 'rolled_back');

                DB::commit();

                return new RollbackResult([
                    'success' => true,
                    'migration_id' => $migrationId,
                    'backup_id' => $backupId,
                    'changes' => $result->changes
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                
                // Attempt recovery
                $this->handleRollbackFailure($e, $migrationRecord, $backupId);
                
                throw $e;
            }

        } catch (\Exception $e) {
            throw new MigrationException('Rollback failed: ' . $e->getMessage());
        }
    }

    private function executeValidation(string $migrationId): ValidationResult
    {
        try {
            // Load migration
            $migration = $this->loadMigration($migrationId);

            // Validate structure
            $structureValidation = $this->validateMigrationStructure($migration);

            // Validate dependencies
            $dependencyValidation = $this->validateDependencies($migration);

            // Validate data integrity
            $integrityValidation = $this->validateDataIntegrity($migration);

            // Validate rollback capability
            $rollbackValidation = $this->validateRollbackCapability($migration);

            // Compile results
            return new ValidationResult([
                'valid' => $structureValidation && 
                          $dependencyValidation && 
                          $integrityValidation && 
                          $rollbackValidation,
                'structure' => $structureValidation,
                'dependencies' => $dependencyValidation,
                'integrity' => $integrityValidation,
                'rollback' => $rollbackValidation
            ]);

        } catch (\Exception $e) {
            throw new MigrationException('Validation failed: ' . $e->getMessage());
        }
    }

    private function loadMigration(string $migrationId): Migration
    {
        $migrationClass = $this->getMigrationClass($migrationId);
        
        if (!class_exists($migrationClass)) {
            throw new MigrationException("Migration class not found: {$migrationClass}");
        }

        return new $migrationClass();
    }

    private function validateMigrationStructure(Migration $migration): bool
    {
        // Verify required methods
        if (!method_exists($migration, 'up') || !method_exists($migration, 'down')) {
            throw new MigrationException('Migration must have up and down methods');
        }

        // Verify schema changes
        foreach ($migration->getSchemaChanges() as $table => $changes) {
            $this->validateSchemaChanges($table, $changes);
        }

        // Verify data changes
        foreach ($migration->getDataChanges() as $table => $changes) {
            $this->validateDataChanges($table, $changes);
        }

        return true;
    }

    private function createBackup(?Migration $migration = null): string
    {
        $backupId = uniqid('backup_', true);
        $tables = $migration ? $migration->getAffectedTables() : $this->getAllTables();

        foreach ($tables as $table) {
            $this->backupTable($table, $backupId);
        }

        return $backupId;
    }

    private function executeMigrationChecks(Migration $migration): void
    {
        // Check dependencies
        $this->checkDependencies($migration);

        // Check conflicts
        $this->checkConflicts($migration);

        // Validate current state
        $this->validateCurrentState($migration);
    }

    private function executeMigrationSteps(Migration $migration): MigrationStepResult
    {
        $result = new MigrationStepResult();

        // Execute schema changes
        foreach ($migration->getSchemaChanges() as $table => $changes) {
            $this->executeSchemaChanges($table, $changes, $result);
        }

        // Execute data changes
        foreach ($migration->getDataChanges() as $table => $changes) {
            $this->executeDataChanges($table, $changes, $result);
        }

        return $result;
    }

    private function verifyMigrationResult(Migration $migration, MigrationStepResult $result): void
    {
        // Verify schema changes
        foreach ($migration->getSchemaChanges() as $table => $changes) {
            $this->verifySchemaChanges($table, $changes);
        }

        // Verify data changes
        foreach ($migration->getDataChanges() as $table => $changes) {
            $this->verifyDataChanges($table, $changes);
        }
    }

    private function recordMigration(Migration $migration, MigrationStepResult $result): void
    {
        MigrationHistory::create([
            'migration_id' => $migration->getId(),
            'batch' => $this->getNextBatch(),
            'changes' => $result->changes,
            'executed_at' => now()
        ]);
    }

    private function handleMigrationFailure(\Exception $e, Migration $migration, string $backupId): void
    {
        try {
            // Restore from backup
            $this->restoreFromBackup($backupId);

            // Record failure
            $this->recordMigrationFailure($migration, $e);

            // Notify administrators
            $this->notifyFailure($migration, $e);

        } catch (\Exception $restoreException) {
            // Log critical failure
            $this->audit->logCriticalFailure($restoreException, [
                'migration_id' => $migration->getId(),
                'original_error' => $e->getMessage()
            ]);
        }
    }

    private function loadMigrationRecord(string $migrationId): MigrationHistory
    {
        return MigrationHistory::where('migration_id', $migrationId)
                              ->latest('executed_at')
                              ->firstOrFail();
    }

    private function isRollbackPossible(MigrationHistory $record): bool
    {
        return $record->batch === $this->getCurrentBatch() &&
               $record->status !== 'rolled_back';
    }

    private function executeRollbackSteps(MigrationHistory $record): RollbackStepResult
    {
        $migration = $this->loadMigration($record->migration_id);
        $result = new RollbackStepResult();

        // Rollback data changes in reverse order
        $dataChanges = array_reverse($record->changes['data'] ?? []);
        foreach ($dataChanges as $table => $changes) {
            $this->rollbackDataChanges($table, $changes, $result);
        }

        // Rollback schema changes in reverse order
        $schemaChanges = array_reverse($record->changes['schema'] ?? []);
        foreach ($schemaChanges as $table => $changes) {
            $this->rollbackSchemaChanges($table, $changes, $result);
        }

        return $result;
    }

    private function verifyRollbackResult(MigrationHistory $record, RollbackStepResult $result): void
    {
        foreach ($record->changes['schema'] ?? [] as $table => $changes) {
            $this->verifySchemaRollback($table, $changes);
        }

        foreach ($record->changes['data'] ?? [] as $table => $changes) {
            $this->verifyDataRollback($table, $changes);
        }
    }

    private function validateDependencies(Migration $migration): bool
    {
        foreach ($migration->getDependencies() as $dependency) {
            if (!$this->isMigrationApplied($dependency)) {
                return false;
            }
        }
        return true;
    }

    private function validateDataIntegrity(Migration $migration): bool
    {
        foreach ($migration->getAffectedTables() as $table) {
            if (!$this->validateTableIntegrity($table)) {
                return false;
            }
        }
        return true;
    }

    private function validateRollbackCapability(Migration $migration): bool
    {
        return method_exists($migration, 'down') &&
               $this->validateRollbackChanges($migration->getRollbackChanges());
    }

    private function backupTable(string $table, string $backupId): void
    {
        $backupTable = "{$table}_backup_{$backupId}";
        
        Schema::create($backupTable, function ($table) use ($table) {
            DB::statement("CREATE TABLE {$backupTable} LIKE {$table}");
            DB::statement("INSERT INTO {$backupTable} SELECT * FROM {$table}");
        });
    }

    private function restoreFromBackup(string $backupId): void
    {
        $backupTables = $this->getBackupTables($backupId);
        
        foreach ($backupTables as $backupTable) {
            $originalTable = str_replace("_backup_{$backupId}", '', $backupTable);
            
            DB::statement("TRUNCATE TABLE {$originalTable}");
            DB::statement("INSERT INTO {$originalTable} SELECT * FROM {$backupTable}");
            Schema::drop($backupTable);
        }
    }

    private function getBackupTables(string $backupId): array
    {
        return DB::select("SHOW TABLES LIKE '%_backup_{$backupId}'");
    }

    private function getCurrentBatch(): int
    {
        return MigrationHistory::max('batch') ?? 0;
    }

    private function getNextBatch(): int
    {
        return $this->getCurrentBatch() + 1;
    }

    private function isMigrationApplied(string $migrationId): bool
    {
        return MigrationHistory::where('migration_id', $migrationId)
                              ->where('status', 'completed')
                              ->exists();
    }

    private function validateTableIntegrity(string $table): bool
    {
        return DB::select("CHECK TABLE {$table}")[0]->Msg_text === 'OK';
    }

    private function notifyFailure(Migration $migration, \Exception $e): void
    {
        // Implement notification logic
    }
}