<?php

namespace App\Core\Repositories;

use App\Models\Export;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class ExportRepository extends AdvancedRepository
{
    protected $model = Export::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function createExport(array $data): Export
    {
        return $this->executeTransaction(function() use ($data) {
            return $this->create([
                'type' => $data['type'],
                'format' => $data['format'],
                'filters' => $data['filters'] ?? [],
                'options' => $data['options'] ?? [],
                'status' => 'pending',
                'created_by' => auth()->id(),
                'created_at' => now()
            ]);
        });
    }

    public function updateProgress(Export $export, int $processed): void
    {
        $this->executeTransaction(function() use ($export, $processed) {
            $export->update([
                'processed_items' => $processed,
                'updated_at' => now()
            ]);
        });
    }

    public function setOutputFile(Export $export, string $path, int $size): void
    {
        $this->executeTransaction(function() use ($export, $path, $size) {
            $export->update([
                'file_path' => $path,
                'file_size' => $size,
                'updated_at' => now()
            ]);
        });
    }

    public function markAsCompleted(Export $export): void
    {
        $this->executeTransaction(function() use ($export) {
            $export->update([
                'status' => 'completed',
                'completed_at' => now()
            ]);
        });
    }

    public function markAsFailed(Export $export, string $error): void
    {
        $this->executeTransaction(function() use ($export, $error) {
            $export->update([
                'status' => 'failed',
                'error_message' => $error,
                'failed_at' => now()
            ]);
        });
    }

    public function getDownloadableExports(): Collection
    {
        return $this->executeQuery(function() {
            return $this->model
                ->where('status', 'completed')
                ->whereNotNull('file_path')
                ->where('created_at', '>=', now()->subDays(7))
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }
}
