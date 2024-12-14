<?php

namespace App\Core\Security\Validation;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\MetricsCollector;
use App\Core\Audit\AuditLogger;
use App\Core\Security\Encryption\EncryptionService;

class SecurityValidationService implements ValidationInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private AuditLogger $audit;
    private EncryptionService $encryption;

    private const MAX_VALIDATION_TIME = 100; // ms
    private const MAX_RETRIES = 3;

    public function __construct(
        SecurityManager $security,
        MetricsCollector $metrics,
        AuditLogger $audit,
        EncryptionService $encryption
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->audit = $audit;
        $this->encryption = $encryption;
    }

    public function validateOperation(Operation $operation): ValidationResult
    {
        $operationId = $this->metrics->startOperation();

        try {
            // Verify security context
            $this->verifySecurityContext();

            // Validate operation integrity
            $this->validateIntegrity($operation);

            // Security validation
            $validationResult = $this->performValidation($operation);

            // Verify validation result
            $this->verifyValidationResult($validationResult);

            $this->audit->logValidation($operation);

            return $validationResult;

        } catch (\Exception $e) {
            $this->handleValidationFailure($e, $operation);
            throw $e;
        } finally {
            $this->metrics->endOperation($operationId);
        }
    }

    private function verifySecurityContext(): void
    {
        if (!$this->security->isContextValid()) {
            throw new SecurityContextException('Invalid security context');
        }

        if ($this->security->hasSecurityViolations()) {
            throw new SecurityException('Active security violations detected');
        }
    }

    private function validateIntegrity(Operation $operation): void
    {
        if (!$this->encryption->verifySignature($operation)) {
            throw new IntegrityException('Operation signature verification failed');
        }

        if (!$this->encryption->verifyData($operation->getData())) {
            throw new IntegrityException('Operation data integrity check failed');
        }
    }

    private function performValidation(Operation $operation): ValidationResult
    {
        $retryCount = 0;
        $lastError = null;

        while ($retryCount < self::MAX_RETRIES) {
            try {
                return $this->executeValidation($operation);
            } catch (RetryableException $e) {
                $lastError = $e;
                $retryCount++;
                
                if ($retryCount >= self::MAX_RETRIES) {
                    throw new ValidationException(
                        'Validation failed after max retries',
                        previous: $e
                    );
                }
                
                $this->handleRetry($retryCount);
            }
        }

        throw new ValidationException(
            'Validation failed',
            previous: $lastError
        );
    }

    private function executeValidation(Operation $operation): ValidationResult
    {
        $startTime = microtime(true);

        // Validate security requirements
        if (!$this->validateSecurityRequirements($operation)) {
            throw new SecurityException('Security requirements validation failed');
        }

        // Validate permissions
        if (!$this->validatePermissions($operation)) {
            throw new PermissionException('Permission validation failed');
        }

        // Validate operation constraints
        if (!$this->validateConstraints($operation)) {
            throw new ConstraintException('Operation constraints validation failed');
        }

        // Check execution time
        if ((microtime(true) - $startTime) * 1000 > self::MAX_VALIDATION_TIME) {
            throw new TimeoutException('Validation timeout exceeded');
        }

        return new ValidationResult(true, $this->generateValidationProof());
    }

    private function validateSecurityRequirements(Operation $operation): bool
    {
        return $this->security->checkSecurityRequirements($operation) &&
               $this->encryption->validateEncryption($operation->getData());
    }

    private function validatePermissions(Operation $operation): bool
    {
        return $this->security->checkPermissions(
            $operation->getRequiredPermissions()
        );
    }

    private function validateConstraints(Operation $operation): bool
    {
        return $this->security->validateConstraints($operation->getConstraints());
    }

    private function verifyValidationResult(ValidationResult $result): void
    {
        if (!$result->isValid()) {
            throw new ValidationException('Invalid validation result');
        }

        if (!$this->verifyValidationProof($result->getProof())) {
            throw new ValidationException('Validation proof verification failed');
        }
    }

    private function generateValidationProof(): string
    {
        return $this->encryption->generateProof([
            'timestamp' => now(),
            'validator' => get_class($this),
            'context' => $this->security->getContextId()
        ]);
    }

    private function verifyValidationProof(string $proof): bool
    {
        return $this->encryption->verifyProof($proof);
    }

    private function handleValidationFailure(\Exception $e, Operation $operation): void
    {
        $this->audit->logFailure('validation_failed', [
            'operation' => $operation->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->recordFailure('validation', [
            'error_type' => get_class($e),
            'operation' => $operation->getId()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityFailure($e);
        }
    }

    private function handleRetry(int $retryCount): void
    {
        $this->audit->logRetry('validation_retry', [
            'attempt' => $retryCount,
            'timestamp' => now()
        ]);

        usleep(100000 * pow(2, $retryCount));
    }
}
