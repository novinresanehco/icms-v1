<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Security\DTO\SecurityContext;
use App\Core\Security\DTO\ValidationResult;
use App\Core\Exceptions\{SecurityException, ValidationException};

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    
    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
    }

    public function validateCriticalOperation(SecurityContext $context): ValidationResult 
    {
        DB::beginTransaction();
        
        try {
            // Input validation
            $this->validateInput($context);
            
            // Authentication check
            $this->validateAuthentication($context);
            
            // Authorization verification
            $this->verifyAuthorization($context);
            
            // Rate limiting check
            $this->checkRateLimit($context);
            
            DB::commit();
            return new ValidationResult(true);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $context);
            throw $e;
        }
    }

    protected function validateInput(SecurityContext $context): void
    {
        $validated = $this->validator->validate(
            $context->getInput(),
            $this->getValidationRules($context->getOperationType())
        );

        if (!$validated->isValid()) {
            $this->auditLogger->logValidationFailure($context, $validated->getErrors());
            throw new ValidationException('Input validation failed');
        }
    }

    protected function validateAuthentication(SecurityContext $context): void 
    {
        if (!$this->accessControl->validateAuthentication($context)) {
            $this->auditLogger->logAuthenticationFailure($context);
            throw new SecurityException('Authentication failed');
        }
    }

    protected function verifyAuthorization(SecurityContext $context): void
    {
        if (!$this->accessControl->checkPermissions($context)) {
            $this->auditLogger->logAuthorizationFailure($context);
            throw new SecurityException('Authorization check failed');
        }
    }

    protected function checkRateLimit(SecurityContext $context): void
    {
        if (!$this->accessControl->checkRateLimit($context)) {
            $this->auditLogger->logRateLimitExceeded($context);
            throw new SecurityException('Rate limit exceeded');
        }
    }

    protected function handleSecurityFailure(\Exception $e, SecurityContext $context): void
    {
        $this->auditLogger->logSecurityFailure($e, $context);
        
        Log::critical('Security failure', [
            'exception' => $e->getMessage(),
            'context' => $context->toArray(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function getValidationRules(string $operationType): array
    {
        return config("validation.rules.{$operationType}", []);
    }

    public function encryptSensitiveData(array $data): array
    {
        foreach ($this->getSensitiveFields() as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->encryption->encrypt($data[$field]);
            }
        }
        return $data;
    }

    public function decryptSensitiveData(array $data): array
    {
        foreach ($this->getSensitiveFields() as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->encryption->decrypt($data[$field]);
            }
        }
        return $data;
    }

    protected function getSensitiveFields(): array
    {
        return config('security.sensitive_fields', []);
    }
}
