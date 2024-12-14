<?php

namespace App\Core\System;

use App\Core\Interfaces\{
    BackupServiceInterface,
    StorageServiceInterface
};
use Illuminate\Support\Facades\{DB, Schema};
use Psr\Log\LoggerInterface;

class BackupService implements BackupServiceInterface
{
    private StorageServiceInterface $storage;
    private LoggerInterface $logger;
    private array $config;

    private const CHUNK_SIZE = 1000;
    private const COMPRESSION_LEVEL = 9;
    private const BACKUP_TIMEOUT = 3600;
    private const VERIFICATION_BATCH_SIZE = 100;

    public function __construct(
        StorageServiceInterface $storage,
        LoggerInterface $logger
    ) {
        $this->storage = $storage;
        $this->logger = $logger;
        $this->config = config('backup');
    }

    public function createBackup(): string 
    {
        try {
            $backupId = $this->generateBackupId();
            $backupPath = $this->getBackupPath($backupId);
            
            DB::beginTransaction();

            // Create backup metadata
            $metadata = $this->createBackupMetadata($backupId);
            
            // Backup tables
            $tables = Schema::getTables();
            foreach ($tables as $table) {
                $this->backupTable($table, $backupPath);
            }

            // Store metadata
            $this->storage->put(
                $backupPath . '/metadata.json',
                json_encode($metadata)
            );

            // Verify backup integrity
            $this->verifyBackup($backupId);

            DB::commit();

            $this->logger->info('Backup created successfully', [
                'backup_id' => $backupId,
                'tables' => count($tables)
            ]);

            return $backupId;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleBackupError($e, $backupId ?? null);
            throw $e;
        }
    }

    public function restore(string $backupId): bool 
    {
        try {
            $backupPath = $this->getBackupPath($backupId);
            
            if (!$this->storage->exists($backupPath)) {
                throw new BackupException("Backup not found: {$backupId}");
            }

            DB::beginTransaction();

            // Load and verify metadata
            $metadata = $this->loadBackupMetadata($backupId);
            $this->verifyBackupIntegrity($backupId, $metadata);
            
            // Drop existing tables
            Schema::dropAllTables();
            
            // Restore tables
            foreach ($metadata['tables'] as $table) {
                $this->restoreTable($table, $backupPath);
            }

            // Verify restoration
            $this->verifyRestoration($metadata);

            DB::commit();

            $this->logger->info('Backup restored successfully', [
                'backup_id' => $backupId
            ]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleRestoreError($e, $backupId);
            throw $e;
        }
    }

    protected function backupTable(string $table, string $backupPath): void
    {
        $tablePath = $backupPath . '/' . $table;
        $this->storage->makeDirectory($tablePath);

        // Backup table structure
        $structure = Schema::getConnection()
            ->getDoctrineSchemaManager()
            ->createSchema()
            ->getTable($table)
            ->toArray();

        $this->storage->put(
            $tablePath . '/structure.json',
            json_encode($structure)
        );

        // Backup data in chunks
        $count = DB::table($table)->count();
        $chunks = ceil($count / self::CHUNK_SIZE);

        for ($i = 0; $i < $chunks; $i++) {
            $records = DB::table($table)
                ->offset($i * self::CHUNK_SIZE)
                ->limit(self::CHUNK_SIZE)
                ->get();

            $this->storage->put(
                $tablePath . "/data_{$i}.json.gz",
                gzencode(json_encode($records), self::COMPRESSION_LEVEL)
            );

            // Verify chunk integrity
            $this->verifyChunkIntegrity($tablePath . "/data_{$i}.json.gz", count($records));
        }
    }

    protected function restoreTable(string $table, string $backupPath): void
    {
        $tablePath = $backupPath . '/' . $table;

        // Restore structure
        $structure = json_decode(
            $this->storage->get($tablePath . '/structure.json'),
            true
        );
        
        Schema::create($table, function($table) use ($structure) {
            foreach ($structure['columns'] as $column) {
                $this->createColumn($table, $column);
            }

            foreach ($structure['indexes'] as $index) {
                $this->createIndex($table, $index);
            }
        });

        // Restore data chunks
        $chunks = $this->storage->files($tablePath);
        foreach ($chunks as $chunk) {
            if (!str_starts_with($chunk, 'data_')) continue;

            $records = json_decode(
                gzdecode($this->storage->get($tablePath . '/' . $chunk)),
                true
            );

            foreach (array_chunk($records, self::VERIFICATION_BATCH_SIZE) as $batch) {
                DB::table($table)->insert($batch);
            }
        }

        // Verify table restoration
        $this->verifyTableRestoration($table, $structure);
    }

    protected function createColumn($table, array $column): void
    {
        $type = $column['type'];
        $name = $column['name'];

        $tableColumn = $table->$type($name);

        if ($column['unsigned'] ?? false) {
            $tableColumn->unsigned();
        }

        if ($column['nullable'] ?? false) {
            $tableColumn->nullable();
        }

        if (isset($column['default'])) {
            $tableColumn->default($column['default']);
        }

        if ($column['autoIncrement'] ?? false) {
            $tableColumn->autoIncrement();
        }
    }

    protected function createIndex($table, array $index): void
    {
        switch ($index['type']) {
            case 'primary':
                $table->primary($index['columns']);
                break;
            case 'unique':
                $table->unique($index['columns']);
                break;
            case 'index':
                $table->index($index['columns']);
                break;
            case 'foreign':
                $table->foreign($index['columns'])
                    ->references($index['references']['columns'])
                    ->on($index['references']['table'])
                    ->onDelete($index['onDelete'] ?? 'cascade');
                break;
        }
    }

    protected function verifyBackup(string $backupId): void
    {
        $backupPath = $this->getBackupPath($backupId);
        $metadata = $this->loadBackupMetadata($backupId);

        foreach ($metadata['tables'] as $table) {
            $tablePath = $backupPath . '/' . $table;

            // Verify structure
            if (!$this->storage->exists($tablePath . '/structure.json')) {
                throw new BackupException("Structure file missing for table: {$table}");
            }

            // Verify data chunks
            $chunks = $this->storage->files($tablePath);
            $dataChunks = array_filter($chunks, fn($c) => str_starts_with($c, 'data_'));

            if (empty($dataChunks)) {
                throw new BackupException("No data chunks found for table: {$table}");
            }

            foreach ($dataChunks as $chunk) {
                if (!$this->verifyChunkIntegrity($tablePath . '/' . $chunk)) {
                    throw new BackupException("Data chunk integrity check failed: {$chunk}");
                }
            }
        }
    }

    protected function verifyChunkIntegrity(string $path, int $expectedCount = null): bool
    {
        $data = $this->storage->get($path);
        $decoded = gzdecode($data);

        if ($decoded === false) {
            return false;
        }

        $records = json_decode($decoded, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        if ($expectedCount !== null && count($records) !== $expectedCount) {
            return false;
        }

        return true;
    }

    protected function verifyTableRestoration(string $table, array $structure): void
    {
        // Verify structure
        $currentStructure = Schema::getConnection()
            ->getDoctrineSchemaManager()
            ->createSchema()
            ->getTable($table)
            ->toArray();

        if ($this->compareStructures($structure, $currentStructure) === false) {
            throw new BackupException("Table structure verification failed: {$table}");
        }

        // Verify record count
        $expectedCount = $structure['record_count'] ?? null;
        if ($expectedCount !== null) {
            $actualCount = DB::table($table)->count();
            if ($actualCount !== $expectedCount) {
                throw new BackupException(
                    "Record count mismatch for table {$table}: " .
                    "expected {$expectedCount}, got {$actualCount}"
                );
            }
        }
    }

    protected function compareStructures(array $expected, array $actual): bool
    {
        // Compare columns
        if (count($expected['columns']) !== count($actual['columns'])) {
            return false;
        }

        foreach ($expected['columns'] as $name => $column) {
            if (!isset($actual['columns'][$name])) {
                return false;
            }

            if ($column['type'] !== $actual['columns'][$name]['type']) {
                return false;
            }
        }

        // Compare indexes
        if (count($expected['indexes']) !== count($actual['indexes'])) {
            return false;
        }

        foreach ($expected['indexes'] as $name => $index) {
            if (!isset($actual['indexes'][$name])) {
                return false;
            }

            if ($index['type'] !== $actual['indexes'][$name]['type']) {
                return false;
            }
        }

        return true;
    }

    protected function createBackupMetadata(string $backupId): array
    {
        return [
            'id' => $backupId,
            'timestamp' => time(),
            'tables' => Schema::getTables(),
            'database' => DB::getDatabaseName(),
            'checksum' => $this->generateChecksum()
        ];
    }

    protected function generateBackupId(): string
    {
        return date('Y-m-d_H-i-s') . '_' . bin2hex(random_bytes(8));
    }

    protected function getBackupPath(string $backupId): string
    {
        return $this->config['path'] . '/' . $backupId;
    }

    protected function generateChecksum(): string
    {
        $tables = Schema::getTables();
        $checksums = [];

        foreach ($tables as $table) {
            $checksums[$table] = DB::select("CHECKSUM TABLE {$table}")[0]->Checksum;
        }

        return hash('sha256', json_encode($checksums));
    }

    protected function handleBackupError(\Exception $e, ?string $backupId): void
    {
        $this->logger->error('Backup failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($backupId) {
            $this->storage->deleteDirectory($this->getBackupPath($backupId));
        }
    }

    protected function handleRestoreError(\Exception $e, string $backupId): void
    {
        $this->logger->error('Restore failed', [
            'backup_id' => $backupId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
