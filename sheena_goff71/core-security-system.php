<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{ValidationService, AuditService, MonitoringService};

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private AuditService $auditor; 
    private MonitoringService $monitor;

    public function __construct(
        ValidationService $validator,
        AuditService $auditor,
        MonitoringService $monitor
    ) {
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->monitor = $monitor;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        // Pre-operation validation
        $this->validateContext($context);
        
        // Start monitoring
        $monitoringId = $this->monitor->startOperation($context);
        
        DB::beginTransaction();
        
        try {
            // Execute with monitoring
            $result = $this->monitor->track($monitoringId, $operation);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            
            // Log success
            $this->auditor->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Log failure with full context
            $this->auditor->logFailure($e, $context, $monitoringId);
            
            throw new SystemFailureException($e->getMessage(), previous: $e);
        } finally {
            $this->monitor->stopOperation($monitoringId);
        }
    }

    public function validateAccess(string $resource, array $context): bool
    {
        try {
            $this->validator->validateRequest($context);
            
            if (!$this->validator->checkPermissions($resource, $context)) {
                $this->auditor->logUnauthorizedAccess($resource, $context);
                return false;
            }
            
            return true;
        } catch (\Throwable $e) {
            $this->auditor->logAccessFailure($e, $resource, $context);
            return false;
        }
    }

    protected function validateContext(array $context): void 
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    public function verifyIntegrity(string $resource, array $data): bool 
    {
        try {
            return $this->validator->verifyDataIntegrity($resource, $data);
        } catch (\Throwable $e) {
            $this->auditor->logIntegrityFailure($e, $resource, $data);
            return false;
        }
    }
}

class SystemFailureException extends \RuntimeException {}
class ValidationException extends \RuntimeException {}
class SecurityException extends \RuntimeException {}
