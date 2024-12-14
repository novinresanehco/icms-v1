<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityCoreInterface;
use App\Core\Exception\ValidationException;
use Psr\Log\LoggerInterface;

class ValidationManager implements ValidationManagerInterface
{
    private SecurityCoreInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $activeValidators = [];

    public function __construct(
        SecurityCoreInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function validateOperation(string $operation, array $context): bool
    {
        $validationId = $this->generateValidationId();
        
        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('validation:execute', $context);
            $this->validateOperationContext($operation, $context);
            
            $result = $this->executeValidation($operation, $context);
            $this->verifyValidationResult($result);
            
            $this->logValidation($validationId, $operation, $result);
            
            DB::commit();
            return $result->isValid();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($validationId, $operation, $e);
            throw new ValidationException('Operation validation failed', 0, $e);
        }
    }

    public function validateData(array $data, array $rules): array
    {
        $validationId = $this->generateValidationId();
        
        try {
            $this->security->validateSecureOperation('validation:data', []);
            $this->validateDataContext($data, $rules);
            
            foreach ($rules as $field => $rule) {
                $this->validateField($data[$field] ?? null, $rule, $field);
            }
            
            $this->logDataValidation($validationId, $data, $rules);
            
            return $data;

        } catch (\Exception $e) {
            $this->handleDataValidationFailure($validationId, $data, $e);
            throw new ValidationException('Data validation failed', 0, $e);
        }
    }

    private function validateOperationContext(string $operation, array $context): void
    {
        if (!isset($this->config['operations'][$operation])) {
            throw new ValidationException("Invalid operation: {$operation}");
        }

        foreach ($this->config['required_context'] as $key) {
            if (!isset($context[$key])) {
                throw new ValidationException("Missing required context: {$key}");
            }
        }
    }

    private function executeValidation(string $operation, array $context): ValidationResult
    {
        $validator = $this->getValidator($operation);
        $rules = $this->config['operations'][$operation];
        
        $result = new ValidationResult();
        
        foreach ($rules as $rule) {
            $ruleResult = $validator->validateRule($rule, $context);
            $result->addRuleResult($rule, $ruleResult);
            
            if (!$ruleResult->isValid() && $rule['critical']) {
                break;
            }
        }
        
        return $result;
    }

    private function validateField($value, array $rule, string $field): void
    {
        $validator = $this->getValidator($rule['type']);
        
        if (!$validator->isValid($value, $rule)) {
            throw new ValidationException("Field validation failed: {$field}");
        }
    }

    private function verifyValidationResult(ValidationResult $result): void
    {
        if (!$result->isValid()) {
            $errors = $result->getErrors();
            throw new ValidationException(
                'Validation failed: ' . implode(', ', $errors)
            );
        }
    }

    private function getValidator(string $type): ValidatorInterface
    {
        if (!isset($this->activeValidators[$type])) {
            $this->activeValidators[$type] = $this->createValidator($type);
        }
        
        return $this->activeValidators[$type];
    }

    private function createValidator(string $type): ValidatorInterface
    {
        if (!isset($this->config['validators'][$type])) {
            throw new ValidationException("Unknown validator type: {$type}");
        }
        
        $class = $this->config['validators'][$type];
        return new $class();
    }

    private function handleValidationFailure(string $id, string $operation, \Exception $e): void
    {
        $this->logger->error('Validation failed', [
            'validation_id' => $id,
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'operations' => [
                'create' => ['data', 'security', 'business'],
                'update' => ['data', 'security', 'business'],
                'delete' => ['security', 'business']
            ],
            'required_context' => ['user_id', 'timestamp'],
            'validators' => [
                'data' => DataValidator::class,
                'security' => SecurityValidator::class,
                'business' => BusinessValidator::class
            ]
        ];
    }
}
