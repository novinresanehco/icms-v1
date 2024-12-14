<?php

namespace App\Core\Security\Services;

use App\Core\Interfaces\ValidationInterface;
use App\Core\Security\Models\{ValidationResult, ValidationRule};
use App\Core\Events\ValidationEvent;
use App\Core\Exceptions\ValidationException;
use Illuminate\Support\Facades\Event;

class ValidationService implements ValidationInterface
{
    private array $rules = [];
    private array $customValidators = [];
    private ValidationLogger $logger;

    public function __construct(ValidationLogger $logger)
    {
        $this->logger = $logger;
    }

    public function validateRequest($request): ValidationResult
    {
        $startTime = microtime(true);
        $errors = [];

        try {
            // Input sanitization
            $sanitizedData = $this->sanitizeInput($request->all());

            // Schema validation
            if (!$this->validateSchema($sanitizedData)) {
                throw new ValidationException('Schema validation failed');
            }

            // Business rules validation
            foreach ($this->rules as $rule) {
                if (!$this->validateRule($sanitizedData, $rule)) {
                    $errors[] = $rule->getErrorMessage();
                }
            }

            // Custom validation rules
            foreach ($this->customValidators as $validator) {
                $validationResult = $validator->validate($sanitizedData);
                if (!$validationResult->isValid()) {
                    $errors = array_merge($errors, $validationResult->getErrors());
                }
            }

            $result = new ValidationResult(empty($errors), $errors);

            // Log validation result
            $this->logger->logValidation([
                'duration' => microtime(true) - $startTime,
                'success' => $result->isValid(),
                'errors' => $result->getErrors()
            ]);

            Event::dispatch(new ValidationEvent($result));

            return $result;

        } catch (\Throwable $e) {
            $this->logger->logError('validation_error', $e);
            throw new ValidationException('Validation failed: ' . $e->getMessage());
        }
    }

    public function checkPermissions(SecurityContext $context): bool
    {
        try {
            // Role-based access control
            if (!$this->validateRole($context->getUser(), $context->getRequiredRole())) {
                return false;
            }

            // Permission-based access control
            if (!$this->validatePermissions($context->getUser(), $context->getRequiredPermissions())) {
                return false;
            }

            // Context-based access control
            if (!$this->validateContext($context)) {
                return false;
            }

            $this->logger->logAccess([
                'user' => $context->getUser()->getId(),
                'permissions' => 'granted',
                'context' => $context->getIdentifier()
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->logger->logError('permission_check_error', $e);
            return false;
        }
    }

    private function sanitizeInput(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $sanitized[$key] = $this->sanitizeValue($value);
        }
        return $sanitized;
    }

    private function sanitizeValue($value)
    {
        if (is_array($value)) {
            return $this->sanitizeInput($value);
        }
        
        if (is_string($value)) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $value;
    }

    private function validateSchema(array $data): bool
    {
        // Implement schema validation logic
        return true;
    }

    private function validateRule(array $data, ValidationRule $rule): bool
    {
        return $rule->validate($data);
    }

    private function validateRole($user, $requiredRole): bool
    {
        return $user->hasRole($requiredRole);
    }

    private function validatePermissions($user, array $requiredPermissions): bool
    {
        foreach ($requiredPermissions as $permission) {
            if (!$user->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    private function validateContext(SecurityContext $context): bool
    {
        // Implement context-based validation logic
        return true;
    }

    public function addRule(ValidationRule $rule): void
    {
        $this->rules[] = $rule;
    }

    public function addCustomValidator(CustomValidator $validator): void
    {
        $this->customValidators[] = $validator;
    }
}
