<?php

namespace App\Core\Database;

use Illuminate\Database\Migrations\Migrator;
use App\Core\System\{BackupManager, HealthMonitor};

class DatabaseMigrationManager
{
    private Migrator $migrator;
    private BackupManager $backup;
    private HealthMonitor $health;
    private array $config;

    public function migrate(array $options = []): bool
    {
        try {
            // Pre-migration checks
            $this->validateDatabase();
            $backupId = $this->createBackup();

            // Start migration
            $this->health->startMigration();
            
            DB::beginTransaction();
            
            // Execute migrations
            $result = $this->executeMigrations($options);
            
            // Verify migration success
            if (!$this->verifyMigrations()) {
                throw new MigrationFailedException();
            }

            DB::commit();
            $this->health->completeMigration();

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleMigrationFailure($e, $backupId);
            throw $e;
        }
    }

    public function rollback(string $migration = null): bool
    {
        try {
            $backupId = $this->createBackup();
            
            DB::beginTransaction();
            
            $this->migrator->rollback(
                $this->getMigrationFiles($migration),
                ['pretend' => false]
            );
            
            if (!$this->verifyRollback($migration)) {
                throw new RollbackFailedException();
            }

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRollbackFailure($e, $backupId);
            throw $e;
        }
    }

    protected function validateDatabase(): void
    {
        if (!$this->health->checkDatabaseHealth()) {
            throw new DatabaseHealthException();
        }

        if (!$this->health->checkDatabaseConnections()) {
            throw new DatabaseConnectionException();
        }

        if (!$this->health->checkDatabaseStorage()) {
            throw new InsufficientStorageException();
        }
    }

    protected function createBackup(): string
    {
        $backupId = $this->backup->createDatabaseBackup();
        
        if (!$this->backup->verify($backupId)) {
            throw new BackupFailedException();
        }

        return $backupId;
    }

    protected function executeMigrations(array $options): bool
    {
        $files = $this->getMigrationFiles();
        
        return $this->migrator->run(
            $files,
            array_merge(['pretend' => false], $options)
        );
    }

    protected function verifyMigrations(): bool
    {
        $pendingMigrations = $this->migrator->getPendingMigrations();
        $ranMigrations = $this->migrator->getRepository()->getRan();
        
        return empty($pendingMigrations) && 
               !empty($ranMigrations) && 
               $this->verifyDatabaseIntegrity();
    }

    protected function verifyRollback(string $migration = null): bool
    {
        if ($migration) {
            return !in_array(
                $migration, 
                $this->migrator->getRepository()->getRan()
            );
        }

        return true;
    }

    protected function handleMigrationFailure(\Exception $e, string $backupId): void
    {
        $this->health->recordMigrationFailure($e);
        $this->backup->restoreDatabase($backupId);
        $this->notifyAdministrators($e);
    }

    protected function handleRollbackFailure(\Exception $e, string $backupId): void
    {
        $this->health->recordRollbackFailure($e);
        $this->backup->restoreDatabase($backupId);
        $this->notifyAdministrators($e);
    }

    protected function verifyDatabaseIntegrity(): bool
    {
        return $this->health->verifyDatabaseStructure() && 
               $this->health->verifyDatabaseConstraints() &&
               $this->health->verifyDatabaseIndexes();
    }

    protected function getMigrationFiles(?string $migration = null): array
    {
        $path = database_path('migrations');
        
        if ($migration) {
            return [$path . '/' . $migration . '.php'];
        }

        return $this->migrator->getMigrationFiles($path);
    }

    protected function notifyAdministrators(\Exception $e): void
    {
        Notification::route('mail', config('database.admin_email'))
            ->notify(new DatabaseOperationFailedNotification($e));
    }
}
