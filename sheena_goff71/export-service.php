<?php

namespace App\Core\Export\Services;

use App\Core\Export\Models\ExportJob;
use App\Core\Export\Repositories\ExportRepository;
use Illuminate\Support\Facades\{DB, Storage};

class ExportService
{
    public function __construct(
        private ExportRepository $repository,
        private ExportValidator $validator,
        private ExportProcessor $processor
    ) {}

    public function startExport(array $data, string $type): ExportJob
    {
        $this->validator->validateExport($data, $type);

        return DB::transaction(function () use ($data, $type) {
            $exportJob = $this->repository->create([
                'type' => $type,
                'data' => $data,
                'status' => 'pending',
                'total_records' => 0,
                'processed_records' => 0,
                'format' => $data['format'] ?? 'csv'
            ]);

            $this->processor->process($exportJob);
            return $exportJob;
        });
    }

    public function getStatus(int $jobId): array
    {
        $job = $this->repository->findOrFail($jobId);
        
        return [
            'status' => $job->status,
            'total_records' => $job->total_records,
            'processed_records' => $job->processed_records,
            'progress' => $job->getProgress(),
            'file_path' => $job->isCompleted() ? $job->file_path : null,
            'errors' => $job->errors
        ];
    }

    public function cancel(ExportJob $job): bool
    {
        if (!$job->canCancel()) {
            throw new ExportException('Cannot cancel export in current status');
        }

        return $this->repository->updateStatus($job, 'cancelled');
    }

    public function retry(ExportJob $job): bool
    {
        if (!$job->canRetry()) {
            throw new ExportException('Export cannot be retried');
        }

        $job->resetCounters();
        $this->processor->process($job);

        return true;
    }

    public function getDownloadUrl(ExportJob $job): string
    {
        if (!$job->isCompleted()) {
            throw new ExportException('Export is not completed');
        }

        return Storage::temporaryUrl(
            $job->file_path,
            now()->addHours(24)
        );
    }

    public function cleanupOldExports(int $days = 30): int
    {
        $oldExports = $this->repository->getOlderThan($days);
        $count = 0;

        foreach ($oldExports as $export) {
            if ($export->file_path && Storage::exists($export->file_path)) {
                Storage::delete($export->file_path);
            }
            $export->delete();
            $count++;
        }

        return $count;
    }

    public function getExportTypes(): array
    {
        return config('export.types', []);
    }

    public function getSupportedFormats(): array
    {
        return config('export.formats', ['csv', 'xlsx', 'json']);
    }
}
