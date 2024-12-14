<?php

namespace App\Core\Validation;

use App\Core\Interfaces\ValidationServiceInterface;
use App\Core\Models\{
    ValidationResult,
    ValidationContext,
    ValidationRule
};

/**
 * Critical validation service implementing comprehensive validation protocols
 */
class ValidationService implements ValidationServiceInterface
{
    private RuleRegistry $rules;
    private ValidatorFactory $validators;
    private ValidationLogger $logger;

    public function __construct(
        RuleRegistry $rules,
        ValidatorFactory $validators,
        ValidationLogger $logger
    ) {
        $this->rules = $rules;
        $this->validators = $validators;
        $this->logger = $logger;
    }

    /**
     * Validates data against security rules with comprehensive checks
     *
     * @throws ValidationException
     */
    public function validate(array $data, array $rules): ValidationResult
    {
        // Create validation context
        $context = new ValidationContext($data, $rules);

        try {
            // Start validation monitoring
            $validationId = $this->startValidation($context);

            // Execute validation chain
            $result = $this->executeValidationChain($context);

            // Log successful validation
            $this->logSuccess($validationId, $result);

            return $result;

        } catch (\Throwable $e) {
            // Handle validation failure
            $this->handleValidationFailure($e, $context);

            throw new ValidationException(
                'Critical validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Validates service operation with context
     */
    public function validateOperation(
        string $operation,
        $context,
        array $rules
    ): ValidationResult {
        // Create operation validation context
        $validationContext = new ValidationContext(
            ['operation' => $operation] + $context->toArray(),
            $rules
        );

        return $this->validate(
            $validationContext->getData(),
            $validationContext->getRules()
        );
    }

    /**
     * Verifies operation result validity
     */
    public function verifyResult($result, $context): bool
    {
        try {
            // Get result validation rules
            $rules = $this->rules->getResultValidationRules();

            // Create result validation context
            $validationContext = new ValidationContext(
                ['result' => $result] + $context->toArray(),
                $rules
            );

            // Execute result validation
            $validationResult = $this->executeValidationChain($validationContext);

            return $validationResult->isValid();

        } catch (\Throwable $e) {
            $this->logger->logValidationFailure(
                'Result validation failed',
                [
                    'result' => $result,
                    'context' => $context,