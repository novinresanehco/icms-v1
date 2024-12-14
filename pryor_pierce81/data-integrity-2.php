<?php

namespace App\Core\Data;

class DataIntegrityService
{
    private $validator;
    private $monitor;

    public function validateData(array $data, string $context): void
    {
        $validationId = $this->monitor->startValidation();

        try {
            // Schema validation
            if (!$this->validator->validateSchema($data, $context)) {
                throw new ValidationException('Schema validation failed');
            }

            // Business rules
            if (!$this->validator->validateRules($data, $context)) {
                throw new ValidationException('Business rules validation failed');
            }

            // Security constraints
            if (!$this->validator->validateSecurity($data)) {
                throw new SecurityException('Security validation failed');
            }

            $this->monitor->validationSuccess($validationId);

        } catch (\Exception $e) {
            $this->monitor->validationFailure($validationId, $e);
            throw $e;
        }
    }

    public function verifyIntegrity(array $data, string $hash): bool
    {
        return hash_equals(
            $this->calculateHash($data),
            $hash
        );
    }

    private function calculateHash(array $data): string
    {
        return hash('sha256', json_encode($data));
    }
}
