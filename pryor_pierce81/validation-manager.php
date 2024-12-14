<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\ValidationException;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\DB;

/**
 * Critical Validation Management System
 * SECURITY LEVEL: CRITICAL
 * ERROR TOLERANCE: ZERO
 */
class ValidationManager implements ValidationManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $validators = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Validate data with comprehensive security checks
     *
     * @throws ValidationException If validation fails
     */
    public function validate(mixed $data, array $rules, array $context = []): bool
    {
        $validationId = $this->generateValidationId();

        try {
            DB::beginTransaction();

            // Security validation
            $this->security->validateSecureOperation('validation:execute', [
                'validation_id' => $validationId,
                'context' => $context
            ]);

            // Pre-validation processing
            $this->validateRules($rules);
            $processedData = $this->prepareDataForValidation($data);

            // Execute validation
            $result = $this->executeValidation($processedData, $rules, $context);

            // Post-validation verification
            $this->verifyValidationResult($result, $validationId);

            $this->logValidation($validationId, true);

            DB::commit();

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($validationId, $e);
            throw new ValidationException('Validation operation failed', 0, $e);
        }
    }

    /**
     * Validate cache value with security constraints
     *
     * @throws ValidationException If validation fails
     */
    public function validateCacheValue(mixed $value): bool
    {
        $validationId = $this->generateValidationId();

        try {
            // Security validation
            $this->security->validateSecureOperation('validation:cache', [
                'validation_id' => $validationId
            ]);

            // Size validation
            if (!$this->validateValueSize($value)) {
                throw new ValidationException('Cache value size exceeds limit');
            }

            // Type validation
            if (!$this->validateValueType($value)) {
                throw new ValidationException('Invalid cache value type');
            }

            // Structure validation
            if (!$this->validateValueStructure($value)) {
                throw new ValidationException('Invalid cache value structure');
            }

            $this->logValidation($validationId, true);

            return true;

        } catch (\Exception $e) {
            $this->handleValidationFailure($validationId, $e);
            throw new ValidationException('Cache value validation failed', 0, $e);
        }
    }

    /**
     * Register a custom validator with security checks
     *
     * @throws ValidationException If validator registration fails
     */
    public function registerValidator(string $name, callable $validator): void
    {
        $registrationId = $this->generateValidationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('validation:register', [
                'registration_id' => $registrationId,
                'validator_name' => $name
            ]);

            $this->validateValidatorName($name);
            $this->validateValidator($validator);

            $this->validators[$name] = $this->wrapValidator($validator);

            $this->logValidatorRegistration($registrationId, $name);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($registrationId, $e);
            throw new ValidationException('Validator registration failed', 0, $e);
        }
    }

    private function validateRules(array $rules): void
    {
        foreach ($rules as $field => $rule) {
            if (!$this->isValidRule($rule)) {
                throw new ValidationException("Invalid validation rule for field: $field");
            }
        }
    }

    private function validateValueSize(mixed $value): bool
    {
        return strlen(serialize($value)) <= $this->config['max_value_size'];
    }

    private function validateValueType(mixed $value): bool
    {
        return in_array(gettype($value), $this->config['allowed_types']);
    }

    private function validateValueStructure(mixed $value): bool
    {
        if (is_array($value)) {
            return $this->validateArrayStructure($value);
        }
        return true;
    }

    private function validateArrayStructure(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return false;
            }
            if (is_array($item) && !$this->validateArrayStructure($item)) {
                return false;
            }
        }
        return true;
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_value_size' => 1024 * 1024,
            'allowed_types' => ['string', 'integer', 'float', 'boolean', 'array', 'NULL'],
            'max_validation_time' => 5,
            'strict_mode' => true,
            'validation_logging' => true
        ];
    }

    private function generateValidationId(): string
    {
        return uniqid('validation_', true);
    }

    private function handleValidationFailure(string $validationId, \Exception $e): void
    {
        $this->logger->error('Validation operation failed', [
            'validation_id' => $validationId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->notifyValidationFailure($validationId, $e);
    }
}
