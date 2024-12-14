<?php

namespace App\Core\Validation;

class ValidationManager implements ValidationManagerInterface 
{
    private SecurityManager $security;
    private array $rules;
    private array $sanitizers;
    private array $validators;
    private AuditLogger $logger;

    public function validate(array $data, array $rules, array $options = []): array 
    {
        DB::beginTransaction();
        
        try {
            $this->security->validateCriticalOperation([
                'action' => 'validation.execute',
                'data_type' => $options['data_type'] ?? 'unknown',
                'rules' => $rules
            ]);

            $sanitized = $this->sanitize($data);
            $this->validateRules($sanitized, $rules);
            $validated = $this->executeValidation($sanitized, $rules);

            if (isset($options['custom_validation'])) {
                $validated = $this->executeCustomValidation($validated, $options['custom_validation']);
            }

            $this->logger->logValidation([
                'data_type' => $options['data_type'] ?? 'unknown',
                'rules_applied' => $rules,
                'validation_result' => 'success'
            ]);

            DB::commit();
            return $validated;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $data, $rules);
            throw $e;
        }
    }

    public function validateField($value, string $rule, array $options = []) 
    {
        if (!isset($this->validators[$rule])) {
            throw new ValidationException("Invalid validation rule: {$rule}");
        }

        $validator = $this->validators[$rule];
        return $validator->validate($value, $options);
    }

    public function validateBatch(array $items, array $rules): array 
    {
        $results = [];
        $errors = [];

        foreach ($items as $key => $item) {
            try {
                $results[$key] = $this->validate($item, $rules);
            } catch (ValidationException $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            throw new BatchValidationException($errors);
        }

        return $results;
    }

    private function sanitize(array $data): array 
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);
            } else {
                $sanitized[$key] = $this->sanitizeValue($value);
            }
        }

        return $sanitized;
    }

    private function sanitizeValue($value): mixed 
    {
        foreach ($this->sanitizers as $sanitizer) {
            $value = $sanitizer->sanitize($value);
        }

        return $value;
    }

    private function validateRules(array $data, array $rules): void 
    {
        foreach ($rules as $field => $rule) {
            if (!isset($this->validators[$rule])) {
                throw new ValidationException("Invalid validation rule for {$field}: {$rule}");
            }
        }
    }

    private function executeValidation(array $data, array $rules): array 
    {
        $validated = [];

        foreach ($rules as $field => $rule) {
            if (!isset($data[$field]) && $this->isRequired($rule)) {
                throw new ValidationException("Required field missing: {$field}");
            }

            if (isset($data[$field])) {
                $value = $data[$field];
                $validated[$field] = $this->validateField($value, $rule);
            }
        }

        return $validated;
    }

    private function executeCustomValidation(array $data, callable $validator): array 
    {
        $result = $validator($data);

        if (!is_array($result)) {
            throw new ValidationException('Custom validation must return array');
        }

        return $result;
    }

    private function handleValidationFailure(\Exception $e, array $data, array $rules): void 
    {
        $this->logger->logValidation([
            'error' => $e->getMessage(),
            'data' => $data,
            'rules' => $rules,
            'validation_result' => 'failure'
        ]);

        if (!$e instanceof ValidationException) {
            throw new ValidationException(
                'Validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function isRequired(string $rule): bool 
    {
        return Str::contains($rule, 'required');
    }

    public function addValidator(string $rule, Validator $validator): void 
    {
        $this->validators[$rule] = $validator;
    }

    public function addSanitizer(Sanitizer $sanitizer): void 
    {
        $this->sanitizers[] = $sanitizer;
    }
}
