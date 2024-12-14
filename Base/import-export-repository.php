<?php

namespace App\Repositories;

use App\Models\ImportExport;
use App\Repositories\Contracts\ImportExportRepositoryInterface;
use Illuminate\Support\Collection;

class ImportExportRepository extends BaseRepository implements ImportExportRepositoryInterface
{
    protected array $searchableFields = ['name', 'type', 'status'];
    protected array $filterableFields = ['type', 'status', 'created_by'];

    public function createImportJob(array $data): ImportExport
    {
        return $this->create([
            'name' => $data['name'] ?? 'Import-' . time(),
            'type' => 'import',
            'file_path' => $data['file_path'],
            'entity_type' => $data['entity_type'],
            'options' => $data['options'] ?? [],
            'status' => 'pending',
            'created_by' => auth()->id(),
            'total_records' => $data['total_records'] ?? 0
        ]);
    }

    public function createExportJob(array $data): ImportExport
    {
        return $this->create([
            'name' => $data['name'] ?? 'Export-' . time(),
            'type' => 'export',
            'entity_type' => $data['entity_type'],
            'options' => $data['options'] ?? [],
            'status' => 'pending',
            'created_by' => auth()->id()
        ]);
    }

    public function updateProgress(int $id, int $processed, int $total, array $stats = []): bool
    {
        try {
            return $this->update($id, [
                'processed_records' => $processed,
                'total_records' => $total,
                'stats' => $stats,
                'progress' => ($total > 0) ? round(($processed / $total) * 100) : 0
            ]);
        } catch (\Exception $e) {
            \Log::error('Error updating import/export progress: ' . $e->getMessage());
            return false;
        }
    }

    public function markAsCompleted(int $id, array $summary = []): bool
    {
        try {
            return $this->update($id, [
                'status' => 'completed',
                'completed_at' => now(),
                'summary' => $summary
            ]);
        } catch (\Exception $e) {
            \Log::error('Error marking import/export as completed: ' . $e->getMessage());
            return false;
        }
    }

    public function markAsFailed(int $id, string $error): bool
    {
        try {
            return $this->update($id, [
                'status' => 'failed',
                'error' => $error,
                'completed_at' => now()
            ]);
        } catch (\Exception $e) {
            \Log::error('Error marking import/export as failed: ' . $e->getMessage());
            return false;
        }
    }

    public function getPendingJobs(): Collection
    {
        return $this->model
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function getJobHistory(int $limit = 10): Collection
    {
        return $this->model
            ->with('creator')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
