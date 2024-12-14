<?php

namespace App\Core\Export\Services;

use App\Core\Export\Models\ExportJob;
use Illuminate\Support\Facades\Storage;
use League\Csv\Writer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportProcessor
{
    private const CHUNK_SIZE = 1000;

    public function process(ExportJob $job): void
    {
        try {
            $data = $this->getData($job);
            $job->update([
                'total_records' => count($data),
                'status' => 'processing'
            ]);

            $filePath = $this->exportData($job, $data);
            $job->markAsCompleted($filePath);
        } catch (\Exception $e) {
            $job->markAsFailed($e->getMessage());
        }
    }

    protected function getData(ExportJob $job): array
    {
        $processor = $this->getDataProcessor($job->type);
        return $processor->getData($job->data);
    }

    protected function exportData(ExportJob $job, array $data): string
    {
        return match($job->format) {
            'csv' => $this->exportToCsv($job, $data),
            'xlsx' => $this->exportToXlsx($job, $data),
            'json' => $this->exportToJson($job, $data),
            default => throw new ExportException("Unsupported format: {$job->format}")
        };
    }

    protected function exportToCsv(ExportJob $job, array $data): string
    {
        $csv = Writer::createFromString();
        $csv->insertOne(array_keys(reset($data)));

        foreach ($data as $row) {
            $csv->insertOne($row);
            $job->incrementProcessedRecords();
        }

        $filePath = "exports/{$job->id}.csv";
        Storage::put($filePath, $csv->toString());
        
        return $filePath;
    }

    protected function exportToXlsx(ExportJob $job, array $data): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $headers = array_keys(reset($data));
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($data as $record) {
            $sheet->fromArray($record, null, "A{$row}");
            $job->incrementProcessedRecords();
            $row++;
        }

        $filePath = "exports/{$job->id}.xlsx";
        $writer = new Xlsx($spreadsheet);
        $writer->save(Storage::path($filePath));
        
        return $filePath;
    }

    protected function exportToJson(ExportJob $job, array $data): string
    {
        $filePath = "exports/{$job->id}.json";
        Storage::put($filePath, json_encode($data, JSON_PRETTY_PRINT));
        $job->incrementProcessedRecords(count($data));
        
        return $filePath;
    }

    protected function getDataProcessor(string $type): ExportDataProcessor
    {
        return match($type) {
            'users' => new UserExportProcessor(),
            'products' => new ProductExportProcessor(),
            'orders' => new OrderExportProcessor(),
            default => throw new ExportException("Unknown export type: {$type}")
        };
    }
}
