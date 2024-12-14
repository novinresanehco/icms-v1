<?php
namespace App\Core\Security;

use App\Core\Interfaces\ValidationInterface;
use App\Core\Security\{SecurityContext, DataValidator};
use App\Core\Exceptions\{ValidationException, IntegrityException};

class ValidationService implements ValidationInterface
{
    private DataValidator $validator;
    private SchemaRegistry $schemas;
    private RuleEngine $rules;
    private AuditLogger $audit;

    public function validateInput(array $data, SecurityContext $context): array 
    {
        try {
            $schema = $this->schemas->getInputSchema($context->getOperation());
            
            $validated = $this->validator->validate($data, $schema);
            
            if (!$this->rules->checkBusinessRules($validated, $context)) {
                throw new ValidationException('Business rules validation failed');
            }
            
            $this->validateDataIntegrity($validated);
            
            return $validated;
            
        } catch (\Throwable $e) {
            $this->audit->logValidationFailure($context, $e);
            throw new ValidationException(
                'Input validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function validateOutput($data, SecurityContext $context): bool
    {
        $schema = $this->schemas->getOutputSchema($context->getOperation());
        
        if (!$this->validator->validate($data, $schema)) {
            throw new ValidationException('Output validation failed');
        }

        if (!$this->validateSensitiveData($data)) {
            throw new ValidationException('Sensitive data validation failed');
        }

        return true;
    }

    public function validateDataIntegrity($data): bool
    {
        if (!$this->validator->checkIntegrity($data)) {
            throw new IntegrityException('Data integrity validation failed');
        }

        foreach ($data as $key => $value) {
            if ($this->rules->isHashRequired($key)) {
                $this->validateHash($value, $key);
            }
            
            if ($this->rules->requiresEncryption($key)) {
                $this->validateEncryption($value, $key);
            }
        }

        return true;
    }

    public function validateSecurityContext(SecurityContext $context): void
    {
        if (!$this->validateTokens($context)) {
            throw new ValidationException('Security token validation failed');
        }

        if (!$this->validateHeaders($context)) {
            throw new ValidationException('Security headers validation failed');
        }

        if (!$this->validateSecurityConstraints($context)) {
            throw new ValidationException('Security constraints validation failed');
        }
    }

    private function validateSensitiveData($data): bool
    {
        foreach ($this->rules->getSensitiveFields() as $field) {
            if (isset($data[$field]) && !$this->validateSensitiveField($data[$field])) {
                return false;
            }
        }
        return true;
    }

    private function validateHash($value, string $key): void
    {
        if (!hash_equals($value['hash'], hash_hmac('sha256', $value['data'], config('app.key')))) {
            throw new IntegrityException("Hash validation failed for $key");
        }
    }

    private function validateEncryption($value, string $key): void
    {
        if (!$this->validator->isEncrypted($value)) {
            throw new ValidationException("Encryption required for $key");
        }
    }

    private function validateTokens(SecurityContext $context): bool
    {
        return $this->validator->validateToken($context->getAuthToken()) &&
               $this->validator->validateCsrf($context->getCsrfToken());
    }

    private function validateHeaders(SecurityContext $context): bool
    {
        $required = ['X-Security-Version', 'X-Request-ID', 'X-Client-Token'];
        
        foreach ($required as $header) {
            if (!$context->hasHeader($header)) {
                return false;
            }
        }
        
        return true;
    }

    private function validateSecurityConstraints(SecurityContext $context): bool
    {
        return $this->rules->checkSecurityConstraints($context) &&
               $this->validateRateLimits($context) &&
               $this->validateAccessPatterns($context);
    }
}
