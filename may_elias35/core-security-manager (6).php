<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\{SecurityManagerInterface, ValidationInterface};
use App\Core\Services\{
    EncryptionService,
    AuditLogger,
    AccessControl,
    MonitoringService
};

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationInterface $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private MonitoringService $monitor;

    public function __construct(
        ValidationInterface $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        MonitoringService $monitor
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->monitor = $monitor;
    }

    public function executeCriticalOperation(CriticalOperation $operation): OperationResult 
    {
        $operationId = $this->monitor->startOperation($operation);
        
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $operation->execute();
            
            // Verify result integrity
            $this->verifyResult($result);
            
            // Create backup point
            $this->createSecureBackup($operation, $result);
            
            DB::commit();
            
            $this->auditLogger->logSuccess($operation, $result);
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->handleFailure($e, $operation);
            throw new SecurityException($e->getMessage(), $e->getCode(), $e);
            
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function validateOperation(CriticalOperation $operation): void
    {
        // Input validation
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid operation input');
        }

        // Security validation
        if (!$this->accessControl->validateAccess($operation)) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Rate limiting
        if (!$this->accessControl->checkRateLimit($operation)) {
            throw new RateLimitException('Rate limit exceeded');
        }

        // Additional security checks
        $this->performSecurityChecks($operation);
    }

    private function verifyResult(OperationResult $result): void
    {
        // Data integrity check
        if (!$this->validator->verifyIntegrity($result->getData())) {
            throw new IntegrityException('Result integrity validation failed');
        }

        // Business rule validation
        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }

        // Performance verification
        if (!$this->monitor->verifyPerformance($result)) {
            throw new PerformanceException('Performance requirements not met');
        }
    }

    private function handleFailure(\Exception $e, CriticalOperation $operation): void
    {
        // Log detailed failure
        $this->auditLogger->logFailure($e, [
            'operation' => $operation->toArray(),
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->monitor->captureSystemState()
        ]);

        // Execute emergency protocols if needed
        $this->executeEmergencyProtocols($e, $operation);

        // Update monitoring metrics
        $this->monitor->recordFailure($operation->getType());
    }

    private function createSecureBackup(CriticalOperation $operation, OperationResult $result): void
    {
        $backupData = [
            'operation' => $operation->toArray(),
            'result' => $result->toArray(),
            'timestamp' => now(),
            'checksum' => $this->encryption->generateChecksum($result->getData())
        ];

        $this->encryption->secureStore('backups', $backupData);
    }

    private function performSecurityChecks(CriticalOperation $operation): void
    {
        // IP validation if required
        if ($operation->requiresIpValidation()) {
            $this->accessControl->validateIpAccess();
        }

        // Check for suspicious patterns
        if ($this->detectSuspiciousActivity($operation)) {
            throw new SecurityException('Suspicious activity detected');
        }

        // Verify additional security requirements
        foreach ($operation->getSecurityRequirements() as $requirement) {
            if (!$this->validateSecurityRequirement($requirement)) {
                throw new SecurityException("Security requirement not met: {$requirement}");
            }
        }
    }

    private function executeEmergencyProtocols(\Exception $e, CriticalOperation $operation): void
    {
        $severity = $this->assessFailureSeverity($e);
        
        if ($severity === 'CRITICAL') {
            // Execute critical failure protocol
            $this->executeCriticalFailureProtocol($operation);
        }

        // Notify relevant parties
        $this->notifySecurityTeam($e, $operation);
        
        // Update security metrics
        $this->monitor->updateSecurityMetrics($e, $operation);
    }
}
