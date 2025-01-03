<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityManager;
use App\Core\Metrics\MetricsCollector;
use App\Core\Logging\AuditLogger;

class ValidationService implements ValidationInterface 
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private AuditLogger $logger;
    private array $rules = [];
    private array $customValidators = [];

    public function validate(array $data, array $rules): ValidationResult
    {
        $validationId = $this->generateValidationId();
        
        try {
            $this->validateRules($rules);
            $this->security->validateAccess('validation.execute');

            $result = $this->executeValidation($validationId, $data, $rules);
            $this->logValidation($validationId, $data, $rules, $result);

            return $result;

        } catch (\Exception $e) {
            $this->handleValidationFailure($e, $data, $rules);
            throw $e;
        }
    }

    public function validateBatch(array $items, array $rules): array
    {
        $batchId = $this->generateBatchId();
        $results = [];

        try {
            $this->validateRules($rules);
            $this->security->validateAccess('validation.batch');

            foreach ($items as $key => $data) {
                $results[$key] = $this->validate($data, $rules);
            }

            return $results;

        } catch (\Exception $e) {
            $this->handleBatchFailure($e, $items, $rules);
            throw $e;
        }
    }

    public function addValidator(string $name, callable $validator): void
    {
        try {
            $this->security->validateAccess('validation.add_validator');
            
            if (isset($this->customValidators[$name])) {
                throw new ValidationException("Validator already exists: {$name}");
            }

            $this->customValidators[$name] = $validator;

        } catch (\Exception $e) {
            $this->handleAddValidatorFailure($e, $name);
            throw $e;
        }
    }

    protected function executeValidation(string $validationId, array $data, array $rules): ValidationResult
    {
        $errors = [];
        $startTime = microtime(true);

        foreach ($rules as $field => $fieldRules) {
            $fieldErrors = $this->validateField(
                $data[$field] ?? null,
                $fieldRules,
                $field
            );

            if (!empty($fieldErrors)) {
                $errors[$field] = $fieldErrors;
            }
        }

        $duration = microtime(true) - $startTime;
        $this->recordMetrics($validationId, $duration, !empty($errors));

        return new ValidationResult(empty($errors), $errors);
    }

    protected function validateField($value, array|string $rules, string $field): array
    {
        $errors = [];
        $rules = is_string($rules) ? explode('|', $rules) : $rules;

        foreach ($rules as $rule) {
            $ruleName = $this->parseRule($rule);
            $parameters = $this->parseParameters($rule);

            if (!$this->processRule($ruleName, $value, $parameters, $field)) {
                $errors[] = $this->formatError($ruleName, $field, $parameters);
            }
        }

        return $errors;
    }

    protected function processRule(string $rule, $value, array $parameters, string $field): bool
    {
        if (isset($this->customValidators[$rule])) {
            return $this->customValidators[$rule]($value, $parameters, $field);
        }

        return match($rule) {
            'required' => !empty($value),
            'string' => is_string($value),
            'numeric' => is_numeric($value),
            'integer' => is_int($value) || (is_string($value) && ctype_digit($value)),
            'array' => is_array($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'min' => is_numeric($value) ? $value >= $parameters[0] : strlen($value) >= $parameters[0],
            'max' => is_numeric($value) ? $value <= $parameters[0] : strlen($value) <= $parameters[0],
            'between' => $this->validateBetween($value, $parameters),
            'in' => in_array($value, $parameters),
            'not_in' => !in_array($value, $parameters),
            'regex' => preg_match($parameters[0], $value),
            'date' => strtotime($value) !== false,
            'json' => $this->isValidJson($value),
            default => throw new ValidationException("Unknown validation rule: {$rule}")
        };
    }

    protected function validateBetween($value, array $parameters): bool
    {
        [$min, $max] = $parameters;

        if (is_numeric($value)) {
            return $value >= $min && $value <= $max;
        }

        if (is_string($value)) {
            $length = strlen($value);
            return $length >= $min && $length <= $max;
        }

        if (is_array($value)) {
            $count = count($value);
            return $count >= $min && $count <= $max;
        }

        return false;
    }

    protected function parseRule(string $rule): string
    {
        if (strpos($rule, ':') !== false) {
            return substr($rule, 0, strpos($rule, ':'));
        }
        return $rule;
    }

    protected function parseParameters(string $rule): array
    {
        if (strpos($rule, ':') === false) {
            return [];
        }

        $params = substr($rule, strpos($rule, ':') + 1);
        return explode(',', $params);
    }

    protected function formatError(string $rule, string $field, array $parameters): string
    {
        return strtr($this->getErrorMessage($rule), [
            ':field' => $field,
            ':parameters' => implode(', ', $parameters)
        ]);
    }

    protected function validateRules(array $rules): void
    {
        foreach ($rules as $field => $fieldRules) {
            if (!is_string($fieldRules) && !is_array($fieldRules)) {
                throw new ValidationException("Invalid rules format for field: {$field}");
            }
        }
    }

    protected function isValidJson($value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        try {
            json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return true;
        } catch (\JsonException $e) {
            return false;
        }
    }

    protected function recordMetrics(string $validationId, float $duration, bool $hasErrors): void
    {
        $this->metrics->record([
            'validation.duration' => $duration,
            'validation.errors' => (int)$hasErrors,
            'validation.total' => 1
        ]);
    }

    protected function logValidation(string $validationId, array $data, array $rules, ValidationResult $result): void
    {
        $this->logger->info('Validation executed', [
            'validation_id' => $validationId,
            'rules' => $rules,
            'has_errors' => $result->hasErrors(),
            'error_count' => count($result->getErrors())
        ]);
    }

    private function generateValidationId(): string
    {
        return 'val_' . md5(uniqid(mt_rand(), true));
    }

    private function generateBatchId(): string
    {
        return 'batch_' . md5(uniqid(mt_rand(), true));
    }
}
