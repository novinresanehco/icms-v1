<?php

namespace App\Core\Export\Services;

use App\Exceptions\ExportValidationException;
use Illuminate\Support\Facades\Validator;

class ExportValidator
{
    public function validateExport(array $data, string $type): void
    {
        $this->validateType($type);
        $this->validateData($data);
        $this->validateFormat($data['format'] ?? 'csv');
    }

    protected function validateType(string $type): void
    {
        $allowedTypes = config('export.types', []);
        
        if (!empty($allowedTypes) && !in_array($type, $allowedTypes)) {
            throw new ExportValidationException("Invalid export type: {$type}");
        }
    }

    protected function validateData(array $data): void
    {
        $validator = Validator::make($data, [
            'format' => 'sometimes|string|in:csv,xlsx,json',
            'filters' => 'sometimes|array',
            'options' => 'sometimes|array'
        ]);

        if ($validator->fails()) {
            throw new ExportValidationException(
                'Export validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    protected function validateFormat(string $format): void
    {
        $supportedFormats = ['csv', 'xlsx', 'json'];
        
        if (!in_array($format, $supportedFormats)) {
            throw new ExportValidationException("Unsupported export format: {$format}");
        }
    }
}
