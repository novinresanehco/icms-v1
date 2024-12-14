<?php

namespace App\Core\Import\Services;

use App\Exceptions\ImportValidationException;
use Illuminate\Support\Facades\Validator;

class ImportValidator
{
    public function validateImport(array $data, string $type): void
    {
        $this->validateType($type);
        $this->validateData($data);
        $this->validateFile($data['file_path'] ?? null);
    }

    protected function validateType(string $type): void
    {
        $allowedTypes = config('import.allowed_types', []);
        
        if (!empty($allowedTypes) && !in_array($type, $allowedTypes)) {
            throw new ImportValidationException("Invalid import type: {$type}");
        }
    }

    protected function validateData(array $data): void
    {
        $validator = Validator::make($data, [
            'file_path' => 'required|string',
            'options' => 'sometimes|array'
        ]);

        if ($validator->fails()) {
            throw new ImportValidationException(
                'Import validation failed',
                $validator->errors()->toArray()
            );
        }
    }

    protected function validateFile(?string $path): void
    {
        if (!Storage::exists($path)) {
            throw new ImportValidationException('Import file not found');
        }

        $mimeType = Storage::mimeType($path);
        $allowedTypes = ['text/csv', 'application/vnd.ms-excel'];

        if (!in_array($mimeType, $allowedTypes)) {
            throw new ImportValidationException('Invalid file type');
        }
    }
}
