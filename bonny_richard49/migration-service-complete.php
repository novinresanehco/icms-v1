<?php

namespace App\Core\Database;

use App\Core\Interfaces\{
    MigrationInterface,
    BackupServiceInterface
};
use Illuminate\Support\Facades\{DB, Schema};
use Psr\Log\LoggerInterface;

class MigrationService implements MigrationInterface
{
    private BackupServiceInterface $backup;
    private LoggerInterface $logger;
    private array $config;

    private const BATCH_SIZE = 1000;
    private const TIMEOUT = 300;
    private const MAX_RETRIES = 3;

    public function __construct(
        BackupServiceInterface $backup,
        LoggerInterface $logger
    ) {
        $this->backup = $backup;
        $this->logger = $logger;
        $this->config = config('database.migrations');
    }

    public function migrate(array $migrations): bool
    {
        try {
            $backupId = $this->backup->createBackup();
            
            DB::beginTransaction();

            foreach ($migrations as $migration) {
                $this->executeMigration($migration);
            }

            $this->recordMigration($migrations);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->backup->restore($backupId);
            $this->handleMigrationError($e, $migrations);
            throw $e;
        }
    }

    public function rollback(string $version): bool
    {
        try {
            $backupId = $this->backup->createBackup();
            
            DB::beginTransaction();

            $migrations = $this->getRollbackMigrations($version);
            foreach ($migrations as $migration) {
                $this->executeRollback($migration);
            }

            $this->recordRollback($version);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->backup->restore($backupId);
            $this->handleRollbackError($e, $version);
            throw $e;
        }
    }

    public function reset(): bool 
    {
        try {
            $backupId = $this->backup->createBackup();
            
            DB::beginTransaction();

            Schema::dropAllTables();

            Schema::dropIfExists('migrations');
            $this->createMigrationsTable();

            $this->logger->info('Database reset completed');

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->backup->restore($backupId);
            $this->handleResetError($e);
            throw $e;
        }
    }

    protected function executeMigration(array $migration): void
    {
        $this->logger->info('Executing migration', [
            'name' => $migration['name'],
            'version' => $migration['version']
        ]);

        if ($this->requiresBatching($migration)) {
            $this->executeBatchedMigration($migration);
        } else {
            $this->executeSingleMigration($migration);
        }

        $this->validateMigration($migration);

        $this->logger->info('Migration completed', [
            'name' => $migration['name'],
            'version' => $migration['version']
        ]);
    }

    protected function executeRollback(array $migration): void
    {
        $this->logger->info('Executing rollback', [
            'name' => $migration['name'],
            'version' => $migration['version']
        ]);

        if ($this->requiresBatching($migration)) {
            $this->executeBatchedRollback($migration);
        } else {
            $this->executeSingleRollback($migration);
        }

        $this->validateRollback($migration);

        $this->logger->info('Rollback completed', [
            'name' => $migration['name'],
            'version' => $migration['version']
        ]);
    }

    protected function executeBatchedMigration(array $migration): void
    {
        $total = $this->getRecordCount($migration['table']);
        $batches = ceil($total / self::BATCH_SIZE);

        for ($i = 0; $i < $batches; $i++) {
            $offset = $i * self::BATCH_SIZE;
            
            $records = DB::table($migration['table'])
                ->offset($offset)
                ->limit(self::BATCH_SIZE)
                ->get();

            foreach ($records as $record) {
                $this->processSingleRecord($record, $migration);
            }

            $this->validateBatch($migration, $i + 1, $batches);
        }
    }

    protected function executeBatchedRollback(array $migration): void
    {
        $total = $this->getRecordCount($migration['table']);
        $batches = ceil($total / self::BATCH_SIZE);

        for ($i = 0; $i < $batches; $i++) {
            $offset = $i * self::BATCH_SIZE;
            
            $records = DB::table($migration['table'])
                ->offset($offset)
                ->limit(self::BATCH_SIZE)
                ->get();

            foreach ($records as $record) {
                $this->rollbackSingleRecord($record, $migration);
            }

            $this->validateBatch($migration, $i + 1, $batches);
        }
    }

    protected function validateMigration(array $migration): void
    {
        if (!$this->verifyTableStructure($migration)) {
            throw new MigrationException('Migration validation failed: Invalid table structure');
        }

        if (!$this->verifyDataIntegrity($migration)) {
            throw new MigrationException('Migration validation failed: Data integrity check failed');
        }
    }

    protected function validateRollback(array $migration): void
    {
        if ($this->tableExists($migration['table'])) {
            throw new MigrationException('Rollback validation failed: Table still exists');
        }

        foreach ($migration['dependencies'] ?? [] as $dependency) {
            if ($this->hasDependendentData($dependency)) {
                throw new MigrationException('Rollback validation failed: Dependent data exists');
            }
        }
    }

    protected function validateBatch(array $migration, int $currentBatch, int $totalBatches): void
    {
        if (!$this->verifyBatchIntegrity($migration, $currentBatch)) {
            throw new MigrationException(
                "Batch validation failed: Batch {$currentBatch} of {$totalBatches}"
            );
        }
    }

    protected function verifyTableStructure(array $migration): bool
    {
        $table = $migration['table'];
        $expectedColumns = $migration['columns'];

        $actualColumns = Schema::getColumnListing($table);
        $actualTypes = [];

        foreach ($actualColumns as $column) {
            $actualTypes[$column] = Schema::getColumnType($table, $column);
        }

        foreach ($expectedColumns as $column => $type) {
            if (!isset($actualTypes[$column]) || $actualTypes[$column] !== $type) {
                return false;
            }
        }

        return true;
    }

    protected function verifyDataIntegrity(array $migration): bool
    {
        $constraints = $migration['constraints'] ?? [];
        
        foreach ($constraints as $constraint) {
            if (!$this->validateConstraint($constraint)) {
                return false;
            }
        }

        return true;
    }

    protected function validateConstraint(array $constraint): bool
    {
        $query = DB::table($constraint['table']);

        if (isset($constraint['where'])) {
            foreach ($constraint['where'] as $column => $value) {
                $query->where($column, $value);
            }
        }

        if (isset($constraint['count'])) {
            return $query->count() === $constraint['count'];
        }

        if (isset($constraint['exists'])) {
            return $constraint['exists'] === $query->exists();
        }

        return true;
    }

    protected function verifyBatchIntegrity(array $migration, int $batch): bool
    {
        $batchData = DB::table($migration['table'])
            ->where('migration_batch', $batch)
            ->get();

        foreach ($batchData as $record) {
            if (!$this->validateRecord($record, $migration)) {
                return false;
            }
        }

        return true;
    }

    protected function validateRecord($record, array $migration): bool
    {
        $rules = $migration['validation_rules'] ?? [];

        foreach ($rules as $column => $rule) {
            if (!$this->validateField($record->{$column}, $rule)) {
                return false;
            }
        }

        return true;
    }

    protected function validateField($value, $rule): bool
    {
        if (is_callable($rule)) {
            return $rule($value);
        }

        if (is_string($rule) && method_exists($this, "validate{$rule}")) {
            return $this->{"validate{$rule}"}($value);
        }

        return true;
    }

    protected function hasDependendentData(array $dependency): bool
    {
        return DB::table($dependency['table'])
            ->where($dependency['column'], $dependency['value'])
            ->exists();
    }

    protected function createMigrationsTable(): void
    {
        Schema::create('migrations', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('version');
            $table->integer('batch');
            $table->timestamp('executed_at');
        });
    }

    protected function recordMigration(array $migrations): void
    {
        $batch = $this->getNextBatchNumber();

        foreach ($migrations as $migration) {
            DB::table('migrations')->insert([
                'name' => $migration['name'],
                'version' => $migration['version'],
                'batch' => $batch,
                'executed_at' => now()
            ]);
        }
    }

    protected function recordRollback(string $version): void
    {
        DB::table('migrations')
            ->where('version', '>=', $version)
            ->delete();
    }

    protected function getNextBatchNumber(): int
    {
        return DB::table('migrations')->max('batch') + 1;
    }

    protected function getRollbackMigrations(string $version): array
    {
        return DB::table('migrations')
            ->where('version', '>=', $version)
            ->orderBy('version', 'desc')
            ->get()
            ->map(function ($migration) {
                return $this->loadMigrationFile($migration->name);
            })
            ->toArray();
    }

    protected function loadMigrationFile(string $name): array
    {
        $path = $this->config['path'] . '/' . $name . '.php';
        
        if (!file_exists($path)) {
            throw new MigrationException("Migration file not found: {$name}");
        }

        return require $path;
    }

    protected function handleMigrationError(\Exception $e, array $migrations): void
    {
        $this->logger->error('Migration failed', [
            'migrations' => array_column($migrations, 'name'),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleRollbackError(\Exception $e, string $version): void
    {
        $this->logger->error('Rollback failed', [
            'version' => $version,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleResetError(\Exception $e): void
    {
        $this->logger->error('Database reset failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
