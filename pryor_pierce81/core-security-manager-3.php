<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\{SecurityManagerInterface, ValidationServiceInterface};
use App\Core\Services\{EncryptionService, AuditService};
use App\Core\Exceptions\{SecurityException, ValidationException};
use App\Core\Security\Context\SecurityContext;

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationServiceInterface $validator;
    private EncryptionService $encryption;
    private AuditService $auditLogger;
    private array $securityConfig;
    
    public function __construct(
        ValidationServiceInterface $validator,
        EncryptionService $encryption,
        AuditService $auditLogger,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->securityConfig = $securityConfig;
    }

    public function executeSecureOperation(callable $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-operation security validation
            $this->validateSecurity($context);
            
            // Execute operation with monitoring
            $startTime = microtime(true);
            $result = $operation();
            $executionTime = microtime(true) - $startTime;
            
            // Validate result
            $this->validateResult($result);
            
            // Log successful operation
            $this->logSuccess($context, $result, $executionTime);
            
            DB::commit();
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $context);
            throw new SecurityException(
                'Security violation: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    protected function validateSecurity(SecurityContext $context): void 
    {
        // Validate authentication
        if (!$this->validator->validateAuthentication($context->getAuthToken())) {
            throw new SecurityException('Invalid authentication');
        }

        // Validate authorization
        if (!$this->validator->validateAuthorization(
            $context->getUserId(),
            $context->getRequiredPermissions()
        )) {
            throw new SecurityException('Unauthorized operation');
        }

        // Validate rate limits
        if (!$this->validator->validateRateLimit($context->getOperationKey())) {
            throw new SecurityException('Rate limit exceeded');
        }

        // Validate input data
        if (!$this->validator->validateData($context->getInputData())) {
            throw new ValidationException('Invalid input data');
        }
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateOutput($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    protected function logSuccess(
        SecurityContext $context,
        $result,
        float $executionTime
    ): void {
        $this->auditLogger->logSecureOperation([
            'user_id' => $context->getUserId(),
            'operation' => $context->getOperationKey(),
            'execution_time' => $executionTime,
            'success' => true,
            'timestamp' => time()
        ]);
    }

    protected function handleSecurityFailure(\Throwable $e, SecurityContext $context): void
    {
        // Log failure with full context
        $this->auditLogger->logSecurityFailure([
            'exception' => $e->getMessage(),
            'user_id' => $context->getUserId(),
            'operation' => $context->getOperationKey(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => time()
        ]);

        // Alert security team for potential threats
        if ($this->isSecurityThreat($e)) {
            $this->alertSecurityTeam($e, $context);
        }
    }

    protected function isSecurityThreat(\Throwable $e): bool
    {
        return $e instanceof SecurityException || 
               $e->getCode() >= $this->securityConfig['threat_threshold'];
    }

    protected function alertSecurityTeam(\Throwable $e, SecurityContext $context): void
    {
        Log::critical('Security threat detected', [
            'exception' => $e->getMessage(),
            'context' => [
                'user_id' => $context->getUserId(),
                'operation' => $context->getOperationKey(),
                'ip_address' => $context->getIpAddress(),
                'timestamp' => time()
            ]
        ]);
    }
}
