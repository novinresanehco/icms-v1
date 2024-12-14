<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\ValidationException;
use Psr\Log\LoggerInterface;

class ValidationService implements ValidationInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function validate(mixed $data, array $rules): array
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();
            
            $this->security->validateContext('validation:execute');
            
            $violations = $this->executeValidation($data, $rules);
            
            if (empty($violations)) {
                $this->logSuccess($operationId, $data, $rules);
                DB::commit();
            } else {
                $this->logViolations($operationId, $violations);
                DB::rollBack();
            }
            
            return $violations;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operationId, $data, $rules, $e);
            throw $e;
        }
    }

    private function executeValidation(mixed $data, array $rules): array
    {
        $violations = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $this->extractValue($data, $field);
            $fieldViolations = $this->validateField($field, $value, $fieldRules);
            
            if (!empty($fieldViolations)) {
                $violations[$field] = $fieldViolations;
            }
        }

        return $violations;
    }

    private function validateField(string $field, mixed $value, array|string $rules): array
    {
        $rulesList = is_string($rules) ? explode('|', $rules) : $rules;
        $violations = [];

        foreach ($rulesList as $rule) {
            [$ruleName, $params] = $this->parseRule($rule);
            
            if (!$this->executeRule($ruleName, $value, $params)) {
                $violations[] = [
                    'rule' => $ruleName,
                    'params' => $params,
                    'message' => $this->getErrorMessage($ruleName, $field, $params)
                ];
            }
        }

        return $violations;
    }

    private function executeRule(string $rule, mixed $value, array $params): bool
    {
        return match($rule) {
            'required' => !empty($value),
            'string' => is_string($value),
            'numeric' => is_numeric($value),
            'array' => is_array($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'max' => is_numeric($value) ? $value <= $params[0] : strlen($value) <= $params[0],
            'min' => is_numeric($value) ? $value >= $params[0] : strlen($value) >= $params[0],
            'in' => in_array($value, $params),
            'regex' => preg_match($params[0], $value) === 1,
            default => throw new ValidationException("Unknown validation rule: {$rule}")
        };
    }

    private function parseRule(string $rule): array 
    {
        $parts = explode(':', $rule, 2);
        $ruleName = $parts[0];
        $params = isset($parts[1]) ? explode(',', $parts[1]) : [];
        
        return [$ruleName, $params];
    }

    private function extractValue(mixed $data, string $field)
    {
        if (is_array($data)) {
            return $data[$field] ?? null;
        }
        
        if (is_object($data)) {
            return $data->{$field} ?? null;
        }
        
        return null;
    }

    private function generateOperationId(): string 
    {
        return uniqid('val_', true);
    }

    private function logSuccess(string $operationId, mixed $data, array $rules): void
    {
        $this->logger->info('Validation completed successfully', [
            'operation_id' => $operationId,
            'data_type' => gettype($data),
            'rules_count' => count($rules)
        ]);
    }

    private function logViolations(string $operationId, array $violations): void
    {
        $this->logger->warning('Validation violations found', [
            'operation_id' => $operationId,
            'violations' => $violations
        ]);
    }

    private function handleFailure(
        string $operationId, 
        mixed $data, 
        array $rules, 
        \Exception $e
    ): void {
        $this->logger->error('Validation failed', [
            'operation_id' => $operationId,
            'data_type' => gettype($data),
            'rules_count' => count($rules),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_validation_time' => 5000,
            'fail_on_first_error' => false,
            'strict_mode' => true
        ];
    }
}
