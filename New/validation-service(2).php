<?php

namespace App\Core\Validation;

/**
 * Core validation service for critical operations
 */
class ValidationService implements ValidationInterface
{
    private SecurityValidator $security;
    private RuleEngine $rules;
    private DataValidator $validator;
    private MonitoringService $monitor;

    public function __construct(
        SecurityValidator $security,
        RuleEngine $rules,
        DataValidator $validator,
        MonitoringService $monitor
    ) {
        $this->security = $security;
        $this->rules = $rules;
        $this->validator = $validator;
        $this->monitor = $monitor;
    }

    public function validateOperation(Operation $operation): ValidationResult
    {
        // Create validation context
        $context = new ValidationContext($operation);

        try {
            // Pre-validation security check
            $this->security->validateAccess($context);

            // Validate operation data
            $this->validateData($operation->getData(), $context);

            // Validate business rules
            $this->validateBusinessRules($operation, $context);

            // Record validation success
            $this->monitor->trackValidation($context);

            return new ValidationResult(true);

        } catch (\Exception $e) {
            $this->handleValidationFailure($e, $context);
            throw $e;
        }
    }

    protected function validateData(array $data, ValidationContext $context): void
    {
        $rules = $this->rules->getRulesForContext($context);

        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException(
                'Data validation failed',
                $this->validator->getErrors()
            );
        }
    }

    protected function validateBusinessRules(Operation $operation, ValidationContext $context): void
    {
        $rules = $this->rules->getBusinessRules($operation);

        foreach ($rules as $rule) {
            if (!$rule->validate($operation)) {
                throw new BusinessRuleException(
                    "Business rule '{$rule->getName()}' validation failed"
                );
            }
        }
    }

    protected function handleValidationFailure(\Exception $e, ValidationContext $context): void
    {
        // Log validation failure
        $this->monitor->logValidationFailure($e, $context);

        // Track metrics
        $this->monitor->trackMetric('validation_failure', [
            'context' => $context->getName(),
            'error' => $e->getMessage()
        ]);
    }
}

class ValidationContext
{
    private Operation $operation;
    private array $metadata;

    public function __construct(Operation $operation)
    {
        $this->operation = $operation;
        $this->metadata = [
            'timestamp' => microtime(true),
            'operation_type' => get_class($operation)
        ];
    }

    public function getName(): string
    {
        return $this->metadata['operation_type'];
    }

    public function getOperation(): Operation
    {
        return $this->operation;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

interface ValidationInterface
{
    public function validateOperation(Operation $operation): ValidationResult;
}

class ValidationResult
{
    private bool $isValid;
    private array $errors;

    public function __construct(bool $isValid, array $errors = [])
    {
        $this->isValid = $isValid;
        $this->errors = $errors;
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
