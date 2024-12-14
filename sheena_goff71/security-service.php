<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{Auth, Log};
use App\Core\Exceptions\SecurityException;

class SecurityService
{
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private ValidationService $validator;

    public function __construct(
        AuditLogger $auditLogger,
        AccessControl $accessControl, 
        ValidationService $validator
    ) {
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->validator = $validator;
    }

    /**
     * Validate security context for operation
     */
    public function validateContext(): void
    {
        if (!Auth::check()) {
            throw new SecurityException('Authentication required');
        }

        if (!$this->accessControl->validateSession()) {
            throw new SecurityException('Invalid session');
        }

        $this->auditLogger->logAccess(Auth::user(), request());
    }

    /**
     * Validate access to resource
     */
    public function validateAccess($resource): void
    {
        if (!$this->accessControl->canAccess($resource)) {
            $this->auditLogger->logUnauthorizedAccess(Auth::user(), $resource);
            throw new SecurityException('Access denied');
        }
    }

    /**
     * Validate permission for operation
     */
    public function validatePermission(string $permission): void
    {
        if (!$this->accessControl->hasPermission($permission)) {
            $this->auditLogger->logPermissionDenied(Auth::user(), $permission);
            throw new SecurityException('Permission denied: ' . $permission);
        }
    }

    /**
     * Execute operation with full security validation
     */
    public function executeSecure(callable $operation, string $permission = null)
    {
        $this->validateContext();
        
        if ($permission) {
            $this->validatePermission($permission);
        }

        try {
            $result = $operation();
            $this->validator->validateResult($result);
            $this->auditLogger->logSuccess(Auth::user(), $result);
            return $result;
            
        } catch (\Exception $e) {
            $this->auditLogger->logFailure(Auth::user(), $e);
            throw new SecurityException(
                'Security validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Validate data encryption
     */
    public function validateEncryption($data): void
    {
        if (!$this->validator->verifyEncryption($data)) {
            throw new SecurityException('Invalid encryption');
        }
    }

    /**
     * Validate data integrity
     */
    public function validateIntegrity($data): void 
    {
        if (!$this->validator->verifyIntegrity($data)) {
            throw new SecurityException('Data integrity violation');
        }
    }
}
