<?php

namespace App\Core\Audit\Services;

use App\Core\Audit\Exceptions\AuditValidationException;

class AuditValidator
{
    public function validate(string $action, string $entityType, array $data): void
    {
        $this->validateAction($action);
        $this->validateEntityType($entityType);
        $this->validateData($data);
    }

    protected function validateAction(string $action): void
    {
        if (empty($action)) {
            throw new AuditValidationException('Audit action cannot be empty');
        }

        $allowedActions = config('audit.allowed_actions', []);
        
        if (!empty($allowedActions) && !in_array($action, $allowedActions)) {
            throw new AuditValidationException("Invalid audit action: {$action}");
        }
    }

    protected function validateEntityType(string $entityType): void
    {
        if (empty($entityType)) {
            throw new AuditValidationException('Entity type cannot be empty');
        }

        $allowedTypes = config('audit.allowed_types', []);
        
        if (!empty($allowedTypes) && !in_array($entityType, $allowedTypes)) {
            throw new AuditValidationException("Invalid entity type: {$entityType}");
        }
    }

    protected function validateData(array $data): void
    {
        try {
            json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AuditValidationException('Audit data must be JSON serializable');
        }
    }
}
