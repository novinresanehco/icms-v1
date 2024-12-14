<?php

namespace App\Core\Repository;

use App\Models\ImportExport;
use App\Core\Events\ImportExportEvents;
use App\Core\Exceptions\ImportExportException;
use Illuminate\Support\Facades\Storage;

class ImportExportRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return ImportExport::class;
    }

    /**
     * Start import process
     */
    public function startImport(UploadedFile $file, string $type, array $options = []): ImportExport
    {
        try {
            // Store import file
            $path = Storage::disk('imports')->putFile('temp', $file);

            // Create import record
            $import = $this->create([
                'type' => $type,
                'file_path' => $path,
                'options' => $options,
                'status' => 'pending',
                'created_by' => auth()->id()
            ]);

            event(new ImportExportEvents\ImportStarted($import));
            return $import;

        } catch (\Exception $e) {
            throw new ImportExportException(
                "Failed to start import: {$e->getMessage()}"
            );
        }
    }

    /**
     * Process import
     */
    public function processImport(int $importId): void
    {
        try {
            $import = $this->find($importId);
            if (!$import) {
                throw new ImportExportException("Import not found with ID: {$importId}");
            }

            // Update status
            $import->update(['status' => 'processing']);

            // Process import based on type
            $result = $this->processImportByType($import);

            // Update import status
            $import->update([
                'status' => 'completed',
                'results' => $result,
                'completed_at' => now()
            ]);

            event(new ImportExportEvents\ImportCompleted($import));

        } catch (\Exception $e) {
            $import->update([
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);
            throw new ImportExportException(
                "Failed to process import: {$e->getMessage()}"
            );
        }
    }

    /**
     * Start export process
     */
    public function startExport(string $type, array $filters = [], array $options = []): ImportExport
    {
        try {
            $export = $this->create([
                'type' => $type,
                'filters' => $filters,
                'options' => $options,
                'status' => 'pending',
                'created_by' => auth()->id()
            ]);

            event(new ImportExportEvents\ExportStarted($export));
            return $export;

        } catch (\Exception $e) {
            throw new ImportExportException(
                "Failed to start export: {$e->getMessage()}"
            );
        }
    }

    /**
     * Process export
     */
    public function processExport(int $exportId): void
    {
        try {
            $export = $this->find($exportId);
            if (!$export) {
                throw new ImportExportException("Export not found with ID: {$exportId}");
            }

            // Update status
            $export->update(['status' => 'processing']);

            // Process export based on type
            $filePath = $this->processExportByType($export);

            // Update export status
            $export->update([
                'status' => 'completed',
                'file_path' => $filePath,
                'completed_at' => now()
            ]);

            event(new ImportExportEvents\ExportCompleted($export));

        } catch (\Exception $e) {
            $export->update([
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);
            throw new ImportExportException(
                "Failed to process export: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get operation status
     */
    public function getOperationStatus(int $id): array
    {
        $operation = $this->find($id);
        if (!$operation) {
            throw new ImportExportException("Operation not found with ID: {$id}");
        }

        return [
            'status' => $operation->status,
            'progress' => $operation->progress ?? 0,
            'error' => $operation->error,
            'results' => $operation->results,
            'file_path' => $operation->file_path
        ];
    }
}
