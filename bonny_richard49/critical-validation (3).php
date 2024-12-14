<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityContext;
use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class ValidationManager implements ValidationManagerInterface
{
    private SecurityContext $securityContext;
    private AuditLogger $auditLogger;
    private RuleEngine $ruleEngine;
    private IntegrityChecker $integrityChecker;

    public function __construct(
        SecurityContext $securityContext,
        AuditLogger $auditLogger,
        RuleEngine $ruleEngine,
        IntegrityChecker $integrityChecker
    ) {
        $this->securityContext = $securityContext;
        $this->auditLogger = $auditLogger;
        $this->ruleEngine = $ruleEngine;
        $this->integrityChecker = $integrityChecker;
    }

    public function validateCriticalOperation(CriticalOperation $operation): ValidationResult 
    {
        DB::beginTransaction();
        
        try {
            $this->validateSecurity($operation);
            $this->validateBusiness($operation);
            $this->validateIntegrity($operation);
            
            DB::commit();
            return new ValidationResult(true);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $operation);
            throw $e;
        }
    }

    private function validateSecurity(CriticalOperation $operation): void 
    {
        if (!$this->securityContext->validateAccess($operation)) {
            throw new SecurityValidationException('Access denied');
        }

        if (!$this->securityContext->validatePermissions($operation)) {
            throw new SecurityValidationException('Insufficient permissions');
        }

        $this->auditLogger->logSecurityValidation($operation);
    }

    private function validateBusiness(CriticalOperation $operation): void 
    {
        $rules = $this->ruleEngine->getRulesForOperation($operation);
        
        foreach ($rules as $rule) {
            if (!$rule->validate($operation)) {
                throw new BusinessValidationException($rule->getMessage());
            }
        }

        $this->auditLogger->logBusinessValidation($operation);
    }

    private function validateIntegrity(CriticalOperation $operation): void 
    {
        if (!$this->integrityChecker->verify($operation)) {
            throw new IntegrityValidationException('Integrity check failed');
        }

        $this->auditLogger->logIntegrityValidation($operation);
    }

    private function handleValidationFailure(\Exception $e, CriticalOperation $operation): void 
    {
        $this->auditLogger->logValidationFailure($e, $operation);
    }
}

class RuleEngine
{
    private array $rules = [];

    public function addRule(ValidationRule $rule): void 
    {
        $this->rules[] = $rule;
    }

    public function getRulesForOperation(CriticalOperation $operation): array 
    {
        return array_filter($this->rules, function($rule) use ($operation) {
            return $rule->appliesTo($operation);
        });
    }
}

abstract class ValidationRule
{
    abstract public function validate(CriticalOperation $operation): bool;
    abstract public function getMessage(): string;
    abstract public function appliesTo(CriticalOperation $operation): bool;
}

class IntegrityChecker 
{
    private HashGenerator $hashGenerator;
    private SignatureVerifier $signatureVerifier;

    public function verify(CriticalOperation $operation): bool 
    {
        $hash = $this->hashGenerator->generate($operation);
        return $this->signatureVerifier->verify($hash, $operation->getSignature());
    }
}

class ValidationResult 
{
    private bool $success;
    private array $errors;

    public function __construct(bool $success, array $errors = []) 
    {
        $this->success = $success;
        $this->errors = $errors;
    }

    public function isSuccessful(): bool 
    {
        return $this->success;
    }

    public function getErrors(): array 
    {
        return $this->errors;
    }
}
