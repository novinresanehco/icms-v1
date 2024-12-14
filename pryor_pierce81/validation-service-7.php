<?php

namespace App\Core\Services;

use App\Core\Interfaces\ValidationInterface;
use App\Core\Exceptions\{ValidationException, SecurityException};
use App\Core\Security\SecurityContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException as LaravelValidationException;

class ValidationService implements ValidationInterface
{
    private array $securityRules;
    private array $customValidators;
    private DataIntegrityService $integrityService;

    public function __construct(
        array $securityRules,
        array $customValidators,
        DataIntegrityService $integrityService
    ) {
        $this->securityRules = $securityRules;
        $this->customValidators = $customValidators;
        $this->integrityService = $integrityService;
    }

    public function validateContext(SecurityContext $context): bool
    {
        try {
            // Validate basic structure
            $this->validateStructure($context);

            // Validate security constraints
            $this->validateSecurityConstraints($context);

            // Validate data integrity
            $this->validateDataIntegrity($context);

            // Validate business rules
            $this->validateBusinessRules($context);

            return true;
        } catch (\Exception $e) {
            throw new ValidationException(
                'Context validation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    protected function validateStructure(SecurityContext $context): void
    {
        try {
            Validator::make($context->toArray(), [
                'user' => 'required',
                'timestamp' => 'required|numeric',
                'operation' => 'required|string',
                'data' => 'required|array'
            ])->validate();
        } catch (LaravelValidationException $e) {
            throw new ValidationException('Invalid context structure', 0, $e);
        }
    }

    protected function validateSecurityConstraints(SecurityContext $context): void
    {
        foreach ($this->securityRules as $rule) {
            if (!$rule->validate($context)) {
                throw new SecurityException("Security constraint failed: {$rule->getName()}");
            }
        }
    }

    protected function validateDataIntegrity(SecurityContext $context): void
    {
        if (!$this->integrityService->verifyIntegrity($context->getData())) {
            throw new SecurityException('Data integrity check failed');
        }
    }

    protected function validateBusinessRules(SecurityContext $context): void
    {
        foreach ($this->customValidators as $validator) {
            if (!$validator->validate($context)) {
                throw new ValidationException("Business rule validation failed: {$validator->getName()}");
            }
        }
    }

    public function checkPermissions(SecurityContext $context): bool
    {
        $requiredPermissions = $this->getRequiredPermissions($context->getOperation());
        $userPermissions = $context->getUser()->getPermissions();

        foreach ($requiredPermissions as $permission) {
            if (!in_array($permission, $userPermissions)) {
                return false;
            }
        }

        return true;
    }

    private function getRequiredPermissions(string $operation): array
    {
        // Get required permissions from configuration based on operation
        return config("permissions.operations.{$operation}", []);
    }
}
