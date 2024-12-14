<?php

namespace App\Core\Security;

use App\Core\Exceptions\{
    SecurityException,
    ValidationException,
    AuthenticationException
};

/**
 * CRITICAL SECURITY CORE
 * Zero-tolerance security implementation
 */
final class SecurityCore
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private EncryptionService $crypto;
    private ValidationService $validator;
    private AuditService $audit;

    public function __construct(
        AuthenticationService $auth,
        AuthorizationService $authz,
        EncryptionService $crypto,
        ValidationService $validator,
        AuditService $audit
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->crypto = $crypto;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function validateSecureOperation(callable $operation, SecurityContext $context): mixed
    {
        // Start security audit
        $auditId = $this->audit->startAudit($context);
        
        try {
            // Validate authentication
            $this->validateAuthentication($context);
            
            // Validate authorization
            $this->validateAuthorization($context);
            
            // Validate input
            $this->validateInput($context);
            
            // Execute with protection
            $result = $this->executeSecure($operation, $context);
            
            // Validate output
            $this->validateOutput($result);
            
            // Record success
            $this->audit->recordSuccess($auditId);
            
            return $result;
            
        } catch (\Throwable $e) {
            // Record security failure
            $this->audit->recordFailure($auditId, $e);
            
            // Handle breach attempt
            $this->handleSecurityBreach($e, $context);
            
            throw new SecurityException(
                'Security validation failed',
                previous: $e
            );
        }
    }

    private function validateAuthentication(SecurityContext $context): void
    {
        if (!$this->auth->validateMultiFactor($context)) {
            $this->audit->logAuthFailure($context);
            throw new AuthenticationException('Multi-factor authentication failed');
        }

        if (!$this->auth->validateSession($context)) {
            $this->audit->logAuthFailure($context);
            throw new AuthenticationException('Session validation failed');
        }
    }

    private function validateAuthorization(SecurityContext $context): void
    {
        if (!$this->authz->validatePermissions($context)) {
            $this->audit->logAuthzFailure($context);
            throw new AuthorizationException('Permission validation failed');
        }

        if (!$this->authz->validateRoles($context)) {
            $this->audit->logAuthzFailure($context);
            throw new AuthorizationException('Role validation failed');
        }
    }

    private function executeSecure(callable $operation, SecurityContext $context): mixed
    {
        return $this->crypto->executeEncrypted(function() use ($operation, $context) {
            return $operation($context);
        });
    }

    private function validateInput(SecurityContext $context): void
    {
        if (!$this->validator->validateInputData($context->getInputData())) {
            throw new ValidationException('Input validation failed');
        }
    }

    private function validateOutput($result): void
    {
        if (!$this->validator->validateOutputData($result)) {
            throw new ValidationException('Output validation failed');
        }
    }

    private function handleSecurityBreach(\Throwable $e, SecurityContext $context): void
    {
        // Lock account on multiple failures
        if ($this->auth->isBreachAttempt($context)) {
            $this->auth->lockAccount($context->getUserId());
        }

        // Block IP on suspicious activity
        if ($this->auth->isSuspiciousActivity($context)) {
            $this->auth->blockIp($context->getIpAddress());
        }

        // Notify security team
        $this->notifySecurityTeam($e, $context);
    }

    private function notifySecurityTeam(\Throwable $e, SecurityContext $context): void
    {
        // Implement security team notification
        // Critical breaches must be reported immediately
    }
}
