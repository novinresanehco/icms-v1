<?php

namespace App\Core\Migration;

use Illuminate\Support\Facades\{DB, Schema, Log};
use App\Core\Security\SecurityManager;
use App\Core\Backup\BackupManager;

class MigrationManager implements MigrationInterface
{
    protected SecurityManager $security;
    protected BackupManager $backup;
    protected MigrationRepository $repository;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        BackupManager $backup,
        MigrationRepository $repository,
        array $config
    ) {
        $this->security = $security;
        $this->backup = $backup;
        $this->repository = $repository;
        $this->config = $config;
    }

    public function installCMS(): void
    {
        $this->security->executeCriticalOperation(function() {
            return DB::transaction(function() {
                $this->createMigrationTable();
                $this->createBackup('pre_install');
                $this->validateSystemRequirements();
                
                try {
                    $migrations = $this->getInstallationMigrations();
                    $this->runMigrations($migrations);
                    $this->seedInitialData();
                    
                    $this->createBackup('post_install');
                    $this->recordInstallation();
                    
                } catch (\Exception $e) {
                    $this->handleMigrationFailure($e, 'installation');
                    throw $e;
                }
            });
        });
    }

    public function update(string $version): void
    {
        $this->security->executeCriticalOperation(function() use ($version) {
            return DB::transaction(function() use ($version) {
                $this->validateUpdatePath($version);
                $this->createBackup('pre_update');
                
                try {
                    $migrations = $this->getUpdateMigrations($version);
                    $this->runMigrations($migrations);
                    
                    $this->createBackup('post_update');
                    $this->recordUpdate($version);
                    
                } catch (\Exception $e) {
                    $this->handleMigrationFailure($e, 'update');
                    throw $e;
                }
            });
        });
    }

    public function rollback(string $version): void
    {
        $this->security->executeCriticalOperation(function() use ($version) {
            return DB::transaction(function() use ($version) {
                $this->validateRollbackPath($version);
                $this->createBackup('pre_rollback');
                
                try {
                    $migrations = $this->getRollbackMigrations($version);
                    $this->runRollbacks($migrations);
                    
                    $this->createBackup('post_rollback');
                    $this->recordRollback($version);
                    
                } catch (\Exception $e) {
                    $this->handleMigrationFailure($e, 'rollback');
                    throw $e;
                }
            });
        });
    }

    protected function createMigrationTable(): void
    {
        if (!Schema::hasTable('migrations')) {
            Schema::create('migrations', function($table) {
                $table->id();
                $table->string('migration');
                $table->string('batch');
                $table->timestamp('executed_at');
                $table->string('status');
                $table->text('error_message')->nullable();
            });
        }
    }

    protected function validateSystemRequirements(): void
    {
        $requirements = [
            'php' => '8.1.0',
            'mysql' => '8.0',
            'extensions' => ['pdo', 'mbstring', 'xml', 'curl'],
            'permissions' => [
                storage_path() => '755',
                public_path() => '755',
                base_path('bootstrap/cache') => '755'
            ]
        ];

        foreach ($requirements['extensions'] as $extension) {
            if (!extension_loaded($extension)) {
                throw new RequirementException("Required PHP extension not loaded: {$extension}");
            }
        }

        foreach ($requirements['permissions'] as $path => $permission) {
            if (!$this->checkPermissions($path, $permission)) {
                throw new RequirementException("Invalid permissions for path: {$path}");
            }
        }
    }

    protected function getInstallationMigrations(): array
    {
        return glob(database_path('migrations/install/*.php'));
    }

    protected function getUpdateMigrations(string $version): array
    {
        return glob(database_path("migrations/updates/{$version}/*.php"));
    }

    protected function getRollbackMigrations(string $version): array
    {
        return array_reverse(glob(database_path("migrations/rollbacks/{$version}/*.php")));
    }

    protected function runMigrations(array $migrations): void
    {
        foreach ($migrations as $migration) {
            $this->runMigration($migration);
        }
    }

    protected function runMigration(string $file): void
    {
        require_once $file;
        $class = $this->getMigrationClass($file);
        $instance = new $class();
        
        try {
            $instance->up();
            $this->recordMigration($file, 'up');
            
        } catch (\Exception $e) {
            $this->recordFailedMigration($file, $e);
            throw $e;
        }
    }

    protected function runRollbacks(array $migrations): void
    {
        foreach ($migrations as $migration) {
            $this->runRollback($migration);
        }
    }

    protected function runRollback(string $file): void
    {
        require_once $file;
        $class = $this->getMigrationClass($file);
        $instance = new $class();
        
        try {
            $instance->down();
            $this->recordMigration($file, 'down');
            
        } catch (\Exception $e) {
            $this->recordFailedMigration($file, $e);
            throw $e;
        }
    }

    protected function validateUpdatePath(string $version): void
    {
        $currentVersion = $this->getCurrentVersion();
        
        if (!$this->isValidUpgradePath($currentVersion, $version)) {
            throw new InvalidUpgradePathException(
                "Cannot upgrade from {$currentVersion} to {$version}"
            );
        }
    }

    protected function validateRollbackPath(string $version): void
    {
        $currentVersion = $this->getCurrentVersion();
        
        if (!$this->isValidRollbackPath($currentVersion, $version)) {
            throw new InvalidRollbackPathException(
                "Cannot rollback from {$currentVersion} to {$version}"
            );
        }
    }

    protected function createBackup(string $stage): void
    {
        $this->backup->createBackup($stage);
    }

    protected function handleMigrationFailure(\Exception $e, string $operation): void
    {
        Log::error("Migration {$operation} failed", [
            'operation' => $operation,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->restoreLatestBackup();
    }

    protected function restoreLatestBackup(): void
    {
        $backup = $this->backup->getLatestBackup();
        $this->backup->restore($backup->id);
    }

    protected function checkPermissions(string $path, string $permission): bool
    {
        return substr(sprintf('%o', fileperms($path)), -4) === $permission;
    }

    protected function getMigrationClass(string $file): string
    {
        return pathinfo($file, PATHINFO_FILENAME);
    }

    protected function getCurrentVersion(): string
    {
        return $this->repository->getCurrentVersion();
    }

    protected function isValidUpgradePath(string $from, string $to): bool
    {
        return version_compare($from, $to, '<');
    }

    protected function isValidRollbackPath(string $from, string $to): bool
    {
        return version_compare($from, $to, '>');
    }

    protected function recordInstallation(): void
    {
        $this->repository->recordInstallation([
            'version' => $this->config['initial_version'],
            'timestamp' => now(),
            'checksum' => $this->generateSystemChecksum()
        ]);
    }

    protected function recordUpdate(string $version): void
    {
        $this->repository->recordUpdate([
            'version' => $version,
            'timestamp' => now(),
            'checksum' => $this->generateSystemChecksum()
        ]);
    }

    protected function recordRollback(string $version): void
    {
        $this->repository->recordRollback([
            'version' => $version,
            'timestamp' => now(),
            'checksum' => $this->generateSystemChecksum()
        ]);
    }

    protected function generateSystemChecksum(): string
    {
        // Generate checksum of critical system files and database
        return hash_file('sha256', base_path('composer.lock'));
    }
}
