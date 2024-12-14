<?php

namespace App\Core\Validation;

class CriticalValidationService
{
    private $rules;
    private $monitor;

    public function validateOperation(Operation $op): bool
    {
        $validationId = $this->monitor->startValidation();

        try {
            // Security validation
            if (!$this->validateSecurity($op)) {
                $this->logFailure('security', $op);
                return false;
            }

            // Data validation
            if (!$this->validateData($op)) {
                $this->logFailure('data', $op);
                return false;
            }

            // Business rules
            if (!$this->validateBusinessRules($op)) {
                $this->logFailure('rules', $op);
                return false;
            }

            $this->monitor->validationSuccess($validationId);
            return true;

        } catch (\Exception $e) {
            $this->monitor->validationError($validationId, $e);
            throw $e;
        }
    }

    private function validateSecurity(Operation $op): bool
    {
        return $this->rules->checkSecurityRules($op);
    }

    private function validateData(Operation $op): bool
    {
        return $this->rules->checkDataRules($op);
    }

    private function validateBusinessRules(Operation $op): bool
    {
        return $this->rules->checkBusinessRules($op);
    }

    private function logFailure(string $type, Operation $op): void
    {
        $this->monitor->logValidationFailure($type, $op);
    }
}
