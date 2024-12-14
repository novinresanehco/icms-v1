<?php

namespace App\Core\CMS;

final class ContentValidationService
{
    private SecurityService $security;
    private array $rules;
    private array $contentTypes;

    public function validateContentCreation(array $data): void
    {
        // Validate required fields
        $this->validateRequired($data, [
            'type',
            'title',
            'content',
            'status'
        ]);

        // Validate content type
        $this->validateContentType($data['type']);
        
        // Validate content structure
        $this->validateContentStructure($data);
        
        // Validate metadata if present
        if (isset($data['metadata'])) {
            $this->validateMetadata($data['metadata']);
        }
        
        // Validate relationships if present
        if (isset($data['relationships'])) {
            $this->validateRelationships($data['relationships']);
        }
        
        // Validate permissions if present
        if (isset($data['permissions'])) {
            $this->validatePermissions($data['permissions']);
        }
    }

    public function validateContentUpdate(array $data): void
    {
        // Validate content structure if present
        if (isset($data['content'])) {
            $this->validateContentStructure($data);
        }
        
        // Validate metadata if updated
        if (isset($data['metadata'])) {
            $this->validateMetadata($data['metadata']);
        }
        
        // Validate relationships if updated
        if (isset($data['relationships'])) {
            $this->validateRelationships($data['relationships']);
        }
        
        // Validate permissions if updated
        if (isset($data['permissions'])) {
            $this->validatePermissions($data['permissions']);
        }
    }

    private function validateRequired(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }
    }

    private function validateContentType(string $type): void
    {
        if (!isset($this->contentTypes[$type])) {
            throw new ValidationException("Invalid content type: {$type}");
        }

        // Validate type-specific rules
        $rules = $this->contentTypes[$type];
        foreach ($rules as $rule => $validator) {
            if (!$validator()) {
                throw new ValidationException("Content type validation failed: {$rule}");
            }
        }
    }

    private function validateContentStructure(array $data): void
    {
        // Structure validation based on type
        $typeRules = $this->rules[$data['type']] ?? null;
        if (!$typeRules) {
            throw new ValidationException("No validation rules for type: {$data['type']}");
        }

        foreach ($typeRules as $field => $rules) {
            if (!$this->validateField($data[$field] ?? null, $rules)) {
                throw new ValidationException("Field validation failed: {$field}");
            }
        }
    }

    private function validateMetadata(array $metadata): void
    {
        // Validate metadata structure
        foreach ($metadata as $key => $value) {
            if (!$this->isValidMetadataKey($key)) {
                throw new ValidationException("Invalid metadata key: {$key}");
            }

            if (!$this->isValidMetadataValue($value)) {
                throw new ValidationException("Invalid metadata value for key: {$key}");
            }
        }
    }

    private function validateRelationships(array $relationships): void
    {
        foreach ($relationships as $type => $items) {
            // Validate relationship type
            if (!$this->isValidRelationType($type)) {
                throw new ValidationException("Invalid relationship type: {$type}");
            }

            // Validate related items
            foreach ($items as $item) {
                if (!$this->isValidRelatedItem($type, $item)) {
                    throw new ValidationException("Invalid relationship item for type: {$type}");
                }
            }
        }
    }

    private function validatePermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            // Validate permission structure
            if (!isset($permission['role'], $permission['access_level'])) {
                throw new ValidationException('Invalid permission structure');
            }

            // Validate role
            if (!$this->security->isValidRole($permission['role'])) {
                throw new ValidationException("Invalid role: {$permission['role']}");
            }

            // Validate access level
            if (!$this->isValidAccessLevel($permission['access_level'])) {
                throw new ValidationException("Invalid access level: {$permission['access_level']}");
            }
        }
    }

    private function isValidAccessLevel(string $level): bool
    {
        return in_array($level, ['read', 'write', 'admin']);
    }

    private function isValidMetadataKey(string $key): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]+$/', $key);
    }

    private function isValidMetadataValue($value): bool
    {
        return is_scalar($value) || is_array($value);
    }
}
