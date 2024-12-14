<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{ValidationService, EncryptionService, AuditService};
use App\Core\Exceptions\{SecurityException, ValidationException};
use Illuminate\Support\Facades\{DB, Log};

class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $audit;
    private array $securityConfig;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $audit,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->securityConfig = $securityConfig;
    }

    public function executeSecureOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $startTime = microtime(true);
            $result = $operation();
            $executionTime = microtime(true) - $startTime;
            
            // Validate result
            $this->validateResult($result);
            
            // Log success and commit
            DB::commit();
            $this->audit->logSuccess($context, $result, $executionTime);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException('Operation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function validateOperation(array $context): void
    {
        // Input validation
        if (!$this->validator->validateInput($context)) {
            throw new ValidationException('Invalid input');
        }

        // Role-based access control
        if (!$this->validator->checkPermission($context)) {
            throw new SecurityException('Access denied');
        }

        // Rate limiting
        if (!$this->validator->checkRateLimit($context)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateOutput($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function handleFailure(\Throwable $e, array $context): void
    {
        // Log the failure with full context
        $this->audit->logFailure($e, $context);
        
        // Alert if needed
        if ($this->isHighSeverity($e)) {
            $this->alertSecurityTeam($e, $context);
        }
    }

    private function isHighSeverity(\Throwable $e): bool
    {
        return $e instanceof SecurityException || 
               $e->getCode() >= $this->securityConfig['high_severity_threshold'];
    }

    private function alertSecurityTeam(\Throwable $e, array $context): void
    {
        Log::critical('Security Alert', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
        // Additional alert mechanisms can be implemented here
    }
}
