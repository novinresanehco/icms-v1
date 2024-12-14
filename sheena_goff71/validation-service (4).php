<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\ValidationException;
use App\Core\Interfaces\ValidationInterface;

class ValidationService implements ValidationInterface
{
    private SecurityConfig $config;
    private AuditLogger $auditLogger;
    private array $validators = [];
    
    public function validateContext(array $context): bool
    {
        try {
            $this->validateStructure($context);
            $this->validateContent($context);
            $this->validateSecurity($context);
            $this->validateCompliance($context);
            
            return true;
        } catch (ValidationException $e) {
            $this->auditLogger->logValidationFailure($e, $context);
            throw $e;
        }
    }

    protected function validateStructure(array $context): void
    {
        foreach ($this->config->getRequiredFields() as $field) {
            if (!isset($context[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }
    }

    protected function validateContent(array $context): void
    {
        foreach ($context as $field => $value) {
            if ($validator = $this->getValidator($field)) {
                if (!$validator->isValid($value)) {
                    throw new ValidationException("Invalid value for {$field}");
                }
            }
        }
    }

    protected function validateSecurity(array $context): void
    {
        if (!$this->validateEncryption($context)) {
            throw new ValidationException("Security validation failed");
        }

        if (!$this->validateSignatures($context)) {
            throw new ValidationException("Signature validation failed");
        }
    }

    protected function validateCompliance(array $context): void
    {
        foreach ($this->config->getComplianceRules() as $rule) {
            if (!$rule->validate($context)) {
                throw new ValidationException("Compliance validation failed: {$rule->getName()}");
            }
        }
    }

    protected function validateEncryption(array $context): bool
    {
        foreach ($this->config->getEncryptedFields() as $field) {
            if (isset($context[$field]) && !$this->isEncrypted($context[$field])) {
                return false;
            }
        }
        return true;
    }

    protected function validateSignatures(array $context): bool
    {
        if (!isset($context['signature'])) {
            return false;
        }

        $payload = $this->preparePayload($context);
        return $this->verifySignature($payload, $context['signature']);
    }

    protected function getValidator(string $field): ?FieldValidator
    {
        return $this->validators[$field] ?? null;
    }

    protected function isEncrypted(string $value): bool
    {
        return str_starts_with($value, $this->config->getEncryptionPrefix());
    }

    protected function preparePayload(array $context): string
    {
        $payload = array_diff_key($context, ['signature' => '']);
        ksort($payload);
        return json_encode($payload);
    }

    protected function verifySignature(string $payload, string $signature): bool
    {
        return hash_equals(
            hash_hmac('sha256', $payload, $this->config->getSigningKey()),
            $signature
        );
    }

    public function validateResult($result): bool
    {
        if (!$this->validateResultStructure($result)) {
            return false;
        }

        if (!$this->validateResultSecurity($result)) {
            return false;
        }

        return $this->validateResultCompliance($result);
    }

    protected function validateResultStructure($result): bool
    {
        return !is_null($result) && 
               is_array($result) && 
               isset($result['status']);
    }

    protected function validateResultSecurity($result): bool
    {
        return isset($result['checksum']) && 
               $this->verifyChecksum($result);
    }

    protected function validateResultCompliance($result): bool
    {
        foreach ($this->config->getResultRules() as $rule) {
            if (!$rule->validate($result)) {
                return false;
            }
        }
        return true;
    }

    protected function verifyChecksum(array $result): bool
    {
        $data = array_diff_key($result, ['checksum' => '']);
        $checksum = hash('sha256', json_encode($data));
        return hash_equals($checksum, $result['checksum']);
    }
}
