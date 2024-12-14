<?php

namespace App\Core\Security;

/**
 * Core security manager handling critical CMS operations with comprehensive
 * protection and monitoring
 */
class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;
    
    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        SecurityConfig $config
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->config = $config;
    }

    public function executeCriticalOperation(CriticalOperation $operation): OperationResult
    {
        // Start transaction and monitoring
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with comprehensive monitoring
            $result = $this->executeWithMonitoring($operation);
            
            // Verify results
            $this->verifyResult($result);
            
            // Commit and log success
            DB::commit();
            $this->logSuccess($operation);
            
            return $result;
            
        } catch (Exception $e) {
            // Rollback and handle failure
            DB::rollBack();
            $this->handleFailure($e, $operation);
            throw new SecurityException("Operation failed: {$e->getMessage()}", 0, $e);
        }
    }

    private function validateOperation(CriticalOperation $operation): void
    {
        // Validate input data
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid input data');
        }

        // Check permissions
        if (!$this->accessControl->hasPermission($operation->getRequiredPermissions())) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Verify integrity
        if (!$this->validator->verifyIntegrity($operation->getData())) {
            throw new IntegrityException('Data integrity check failed');
        }
    }

    private function executeWithMonitoring(CriticalOperation $operation): OperationResult
    {
        $monitor = $this->startMonitoring($operation);
        
        try {
            $result = $operation->execute();
            $monitor->recordSuccess();
            return $result;
        } catch (Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }
    }

    private function handleFailure(Exception $e, CriticalOperation $operation): void
    {
        $this->auditLogger->logFailure([
            'operation' => $operation->getName(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function logSuccess(CriticalOperation $operation): void
    {
        $this->auditLogger->logSuccess([
            'operation' => $operation->getName(),
            'timestamp' => time(),
            'data' => $operation->getAuditData()
        ]);
    }
}
