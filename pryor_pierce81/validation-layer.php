<?php

namespace App\Core\Validation;

class ValidationService
{
    public function validateCriticalData(Operation $op): bool
    {
        $data = $op->getData();

        // Required fields
        if (!$this->validateRequired($data)) {
            return false;
        }

        // Data format
        if (!$this->validateFormat($data)) {
            return false;
        }

        // Business rules
        if (!$this->validateBusinessRules($data)) {
            return false;
        }

        return true;
    }

    protected function validateRequired(array $data): bool
    {
        $required = ['title', 'content', 'author'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    protected function validateFormat(array $data): bool
    {
        $rules = [
            'title' => 'string|max:200',
            'content' => 'string',
            'author' => 'int'
        ];

        foreach ($rules as $field => $rule) {
            if (!$this->checkFormat($data[$field], $rule)) {
                return false;
            }
        }

        return true;
    }

    protected function validateBusinessRules(array $data): bool
    {
        // Implement critical business rules validation
        return true;
    }
}
