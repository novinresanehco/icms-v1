<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Logging\AuditLogger;
use App\Core\Validation\ValidationService;
use App\Core\Exceptions\SecurityException;

class SecurityManager
{
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function __construct(
        ValidationService $validator,
        AuditLogger $auditLogger
    ) {
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    public function executeSecure(callable $operation, array $context = []): mixed
    {
        $this->validateContext($context);
        
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            $result = $operation();
            
            // Verify operation result
            $this->validateResult($result);
            
            DB::commit();
            
            // Log successful operation
            $this->auditLogger->logSuccess(
                operation: get_class($operation),
                context: $context,
                duration: microtime(true) - $startTime
            );
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Log failure with full context
            $this->auditLogger->logFailure(
                exception: $e,
                context: $context,
                stackTrace: $e->getTraceAsString()
            );
            
            throw new SecurityException(
                message: 'Operation failed security checks',
                code: $e->getCode(),
                previous: $e
            );
        }
    }

    public function validateAccess(string $resource, string $action): bool
    {
        // Check current user permissions
        $user = auth()->user();
        
        if (!$user) {
            throw new SecurityException('User not authenticated');
        }

        if (!$user->can("{$action}_{$resource}")) {
            $this->auditLogger->logUnauthorizedAccess(
                user: $user,
                resource: $resource,
                action: $action
            );
            return false;
        }

        return true;
    }

    private function validateContext(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new SecurityException('Invalid security context');
        }
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Operation result failed validation');
        }
    }
}
