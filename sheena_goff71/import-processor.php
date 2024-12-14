<?php

namespace App\Core\Import\Services;

use App\Core\Import\Models\ImportJob;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

class ImportProcessor
{
    private const CHUNK_SIZE = 1000;

    public function process(ImportJob $job): void
    {
        try {
            $file = Storage::get($job->data['file_path']);
            $csv = Reader::createFromString($file);
            $csv->setHeaderOffset(0);

            $totalRows = count($csv);
            $job->update([
                'total_rows' => $totalRows,
                'status' => 'processing'
            ]);

            foreach ($csv->chunk(self::CHUNK_SIZE) as $chunk) {
                $this->processChunk($job, $chunk);
            }

            if ($job->failed_rows === 0) {
                $job->markAsCompleted();
            } else {
                $job->markAsFailed('Import completed with errors');
            }
        } catch (\Exception $e) {
            $job->markAsFailed($e->getMessage());
        }
    }

    protected function processChunk(ImportJob $job, array $rows): void
    {
        foreach ($rows as $row) {
            try {
                $this->validateRow($row);
                $this->processRow($job, $row);
                $job->incrementProcessedRows();
            } catch (\Exception $e) {
                $this->handleRowError($job, $row, $e);
            }
        }
    }

    protected function validateRow(array $row): void
    {
        // Implement row validation logic
    }

    protected function processRow(ImportJob $job, array $row): void
    {
        // Implement row processing logic
    }

    protected function handleRowError(ImportJob $job, array $row, \Exception $e): void
    {
        $job->incrementFailedRows();
        $job->addError([
            'row' => $row,
            'message' => $e->getMessage()
        ]);
    }
}
