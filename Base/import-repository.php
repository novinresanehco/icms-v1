<?php

namespace App\Core\Repositories;

use App\Models\Import;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class ImportRepository extends AdvancedRepository
{
    protected $model = Import::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function createImport(array $data): Import
    {
        return $this->executeTransaction(function() use ($data) {
            return $this->create([
                'type' => $data['type'],
                'file_path' => $data['file_path'],
                'options' => $data['options'] ?? [],
                'status' => 'pending',
                'total_rows' => $data['total_rows'] ?? 0,
                'created_by' => auth()->id(),
                'created_at' => now()
            ]);
        });
    }

    public function updateProgress(Import $import, int $processed, int $successful, int $failed): void
    {
        $this->executeTransaction(function() use ($import, $processed, $successful, $failed) {
            $import->update([
                'processed_rows' => $processed,
                'successful_rows' => $successful,
                'failed_rows' => $failed,
                'updated_at' => now()
            ]);
        });
    }

    public function markAsCompleted(Import $import): void
    {
        $this->executeTransaction(function() use ($import) {
            $import->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);
        });
    }

    public function markAsFailed(Import $import, string $error): void
    {
        $this->executeTransaction(function() use ($import, $error) {
            $import->update([
                'status' => 'failed',
                'error_message' => $error,
                'failed_at' => now()
            ]);
        });
    }

    public function logError(Import $import, array $errorData): void
    {
        $this->executeTransaction(function() use ($import, $errorData) {
            $import->errors()->create([
                'row_number' => $errorData['row_number'],
                'message' => $errorData['message'],
                'data' => $errorData['data'] ?? [],
                'created_at' => now()
            ]);
        });
    }
}
