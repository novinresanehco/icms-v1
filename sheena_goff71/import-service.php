<?php

namespace App\Core\Import\Services;

use App\Core\Import\Models\ImportJob;
use App\Core\Import\Repositories\ImportRepository;
use Illuminate\Support\Facades\{DB, Storage};

class ImportService
{
    public function __construct(
        private ImportRepository $repository,
        private ImportValidator $validator,
        private ImportProcessor $processor
    ) {}

    public function startImport(array $data, string $type): ImportJob
    {
        $this->validator->validateImport($data, $type);

        return DB::transaction(function () use ($data, $type) {
            $importJob = $this->repository->create([
                'type' => $type,
                'data' => $data,
                'status' => 'pending',
                'total_rows' => 0,
                'processed_rows' => 0,
                'failed_rows' => 0
            ]);

            $this->processor->process($importJob);
            return $importJob;
        });
    }

    public function processChunk(ImportJob $job, array $rows): void
    {
        foreach ($rows as $row) {
            try {
                $this->processRow($job, $row);
                $job->incrementProcessedRows();
            } catch (\Exception $e) {
                $this->handleRowFailure($job, $row, $e);
            }
        }

        $this->checkCompletion($job);
    }

    public function getStatus(int $jobId): array
    {
        $job = $this->repository->findOrFail($jobId);
        
        return [
            'status' => $job->status,
            'total_rows' => $job->total_rows,
            'processed_rows' => $job->processed_rows,
            'failed_rows' => $job->failed_rows,
            'progress' => $job->getProgress(),
            'errors' => $job->errors
        ];
    }

    public function cancel(ImportJob $job): bool
    {
        if (!$job->canCancel()) {
            throw new ImportException('Cannot cancel import in current status');
        }

        return $this->repository->updateStatus($job, 'cancelled');
    }

    public function retry(ImportJob $job): bool
    {
        if (!$job->canRetry()) {
            throw new ImportException('Import cannot be retried');
        }

        $job->resetCounters();
        $this->processor->process($job);

        return true;
    }

    protected function processRow(ImportJob $job, array $row): void
    {
        $processor = $this->getRowProcessor($job->type);
        $processor->process($row, $job);
    }

    protected function handleRowFailure(ImportJob $job, array $row, \Exception $e): void
    {
        $job->incrementFailedRows();
        $job->addError([
            'row' => $row,
            'error' => $e->getMessage()
        ]);
    }

    protected function checkCompletion(ImportJob $job): void
    {
        if ($job->isComplete()) {
            $job->markAsCompleted();
        }
    }

    protected function getRowProcessor(string $type): ImportRowProcessor
    {
        return match($type) {
            'users' => new UserImportProcessor(),
            'products' => new ProductImportProcessor(),
            'orders' => new OrderImportProcessor(),
            default => throw new ImportException("Unknown import type: {$type}")
        };
    }
}
