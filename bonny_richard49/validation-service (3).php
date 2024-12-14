<?php

namespace App\Core\Security\Services;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\Exceptions\ValidationException;
use App\Core\Interfaces\ValidationInterface;
use App\Core\Security\Rules\RuleRegistry;

class ValidationService implements ValidationInterface
{
    private RuleRegistry $rules;
    private array $config;
    private array $securityRules;

    public function __construct(
        RuleRegistry $rules,
        array $config,
        array $securityRules
    ) {
        $this->rules = $rules;
        $this->config = $config;
        $this->securityRules = $securityRules;
    }

    public function validateInput(array $data): array
    {
        try {
            $validated = $this->applyValidationRules($data);
            $secured = $this->applySanitization($validated);
            $this->verifySecurityConstraints($secured);
            return $secured;
        } catch (\Exception $e) {
            throw new ValidationException(
                'Input validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function validateResult(OperationResult $result): bool
    {
        return $this->validateOutput($result->getData())
            && $this->verifyResultIntegrity($result)
            && $this->validateBusinessRules($result);
    }

    public function verifyIntegrity(array $data): bool
    {
        $hash = $this->calculateHash($data);
        return hash_equals($data['_hash'] ?? '', $hash);
    }

    private function applyValidationRules(array $data): array
    {
        $validated = [];
        
        foreach ($data as $key => $value) {
            if (!$this->validateField($key, $value)) {
                throw new ValidationException("Invalid field: {$key}");
            }
            $validated[$key] = $this->transformValue($value);
        }

        return $validated;
    }

    private function validateField(string $key, $value): bool
    {
        $rules = $this->rules->getRulesForField($key);
        
        foreach ($rules as $rule) {
            if (!$rule->validate($value)) {
                throw new ValidationException(
                    "Field {$key} failed validation rule: {$rule->getName()}"
                );
            }
        }

        return true;
    }

    private function applySanitization(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            $sanitized[$key] = $this->sanitizeValue($value);
        }

        return $sanitized;
    }

    private function sanitizeValue($value)
    {
        if (is_string($value)) {
            return $this->sanitizeString($value);
        } elseif (is_array($value)) {
            return $this->sanitizeArray($value);
        }
        return $value;
    }

    private function sanitizeString(string $value): string
    {
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $this->applyCustomSanitizers($value);
    }

    private function sanitizeArray(array $value): array
    {
        return array_map([$this, 'sanitizeValue'], $value);
    }

    private function verifySecurityConstraints(array $data): void
    {
        foreach ($this->securityRules as $rule) {
            if (!$rule->verify($data)) {
                throw new SecurityValidationException(
                    "Security constraint violation: {$rule->getMessage()}"
                );
            }
        }
    }

    private function validateOutput(array $data): bool
    {
        try {
            foreach ($data as $key => $value) {
                $this->validateOutputField($key, $value);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function validateOutputField(string $key, $value): void
    {
        $rules = $this->rules->getOutputRulesForField($key);
        
        foreach ($rules as $rule) {
            if (!$rule->validate($value)) {
                throw new ValidationException(
                    "Output validation failed for field {$key}"
                );
            }
        }
    }

    private function verifyResultIntegrity(OperationResult $result): bool
    {
        $data = $result->getData();
        $metadata = $result->getMetadata();
        
        return $this->verifyDataIntegrity($data)
            && $this->verifyMetadataIntegrity($metadata)
            && $this->verifyResultConsistency($data, $metadata);
    }

    private function validateBusinessRules(OperationResult $result): bool
    {
        foreach ($this->rules->getBusinessRules() as $rule) {
            if (!$rule->validate($result)) {
                return false;
            }
        }
        return true;
    }

    private function calculateHash(array $data): string
    {
        ksort($data);
        $serialized = serialize($data);
        return hash_hmac('sha256', $serialized, $this->config['hash_key']);
    }

    private function applyCustomSanitizers(string $value): string
    {
        foreach ($this->config['custom_sanitizers'] as $sanitizer) {
            $value = $sanitizer->sanitize($value);
        }
        return $value;
    }

    private function verifyDataIntegrity(array $data): bool
    {
        return !empty($data) && $this->verifyIntegrity($data);
    }

    private function verifyMetadataIntegrity(array $metadata): bool
    {
        return isset($metadata['timestamp'])
            && isset($metadata['version'])
            && $this->verifyIntegrity($metadata);
    }

    private function verifyResultConsistency(array $data, array $metadata): bool
    {
        return $metadata['data_hash'] === $this->calculateHash($data);
    }

    private function transformValue($value)
    {
        if (is_string($value)) {
            return trim($value);
        } elseif (is_array($value)) {
            return array_map([$this, 'transformValue'], $value);
        }
        return $value;
    }
}
