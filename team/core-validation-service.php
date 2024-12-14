<?php

namespace App\Core\Validation;

use App\Core\Interfaces\ValidationServiceInterface;
use App\Core\Exceptions\ValidationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class ValidationService implements ValidationServiceInterface
{
    private array $config;
    private array $customRules;

    public function __construct(array $config, array $customRules = [])
    {
        $this->config = $config;
        $this->customRules = $customRules;
    }

    public function validateData(array $data, array $rules): bool
    {
        $validator = Validator::make($data, $this->mergeWithDefaultRules($rules));

        if ($validator->fails()) {
            throw new ValidationException(
                'Validation failed: ' . json_encode($validator->errors()->toArray())
            );
        }

        return true;
    }

    public function validateResult($result): bool
    {
        if ($result === null) {
            return false;
        }

        if (is_array($result)) {
            return $this->validateArrayResult($result);
        }

        if (is_object($result)) {
            return $this->validateObjectResult($result);
        }

        return $this->validateScalarResult($result);
    }

    public function verifyIntegrity(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        // Check data structure integrity
        if (!$this->verifyDataStructure($data)) {
            return false;
        }

        // Verify data hash if provided
        if (isset($data['_hash'])) {
            return $this->verifyDataHash($data);
        }

        // Check for required fields
        foreach ($this->config['required_fields'] ?? [] as $field) {
            if (!isset($data[$field])) {
                return false;
            }
        }

        return true;
    }

    private function mergeWithDefaultRules(array $rules): array
    {
        $defaultRules = $this->config['default_rules'] ?? [];
        return array_merge($defaultRules, $rules);
    }

    private function validateArrayResult(array $result): bool
    {
        foreach ($result as $value) {
            if (is_array($value) && !$this->validateArrayResult($value)) {
                return false;
            }
        }
        return true;
    }

    private function validateObjectResult(object $result): bool
    {
        if (method_exists($result, 'validate')) {
            return $result->validate();
        }

        return true;
    }

    private function validateScalarResult($result): bool
    {
        if (is_string($result)) {
            return strlen($result) <= ($this->config['max_string_length'] ?? 65535);
        }

        if (is_numeric($result)) {
            return $result >= ($this->config['min_numeric'] ?? PHP_FLOAT_MIN) && 
                   $result <= ($this->config['max_numeric'] ?? PHP_FLOAT_MAX);
        }

        return true;
    }

    private function verifyDataStructure(array $data): bool
    {
        foreach ($this->config['data_structure'] ?? [] as $key => $type) {
            if (!isset($data[$key])) {
                continue;
            }

            if (!$this->validateDataType($data[$key], $type)) {
                return false;
            }
        }

        return true;
    }

    private function validateDataType($value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'float' => is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            default => true
        };
    }

    private function verifyDataHash(array $data): bool
    {
        $hash = $data['_hash'];
        unset($data['_hash']);

        $calculatedHash = hash_hmac(
            'sha256',
            json_encode($data),
            $this->config['hash_key']
        );

        return hash_equals($calculatedHash, $hash);
    }
}
