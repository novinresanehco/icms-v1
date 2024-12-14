<?php

namespace App\Core\Security;

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
        DB::beginTransaction();
        
        try {
            $this->validateOperation($operation);
            $this->checkPermissions($operation);
            $this->auditLogger->logOperationStart($operation);
            
            $result = $this->executeWithProtection($operation);
            
            $this->validateResult($result);
            $this->auditLogger->logOperationSuccess($operation, $result);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation);
            throw new SecurityException('Operation failed: ' . $e->getMessage());
        }
    }

    private function validateOperation(CriticalOperation $operation): void 
    {
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid operation data');
        }

        if (!$this->validator->checkSecurityConstraints($operation)) {
            throw new SecurityConstraintException('Security constraints not met');
        }
    }

    private function checkPermissions(CriticalOperation $operation): void 
    {
        if (!$this->accessControl->hasPermission($operation->getRequiredPermissions())) {
            throw new UnauthorizedException('Insufficient permissions');
        }
    }

    private function executeWithProtection(CriticalOperation $operation): OperationResult 
    {
        $startTime = microtime(true);
        
        try {
            $result = $operation->execute();
            
            $executionTime = microtime(true) - $startTime;
            if ($executionTime > $this->config->getMaxExecutionTime()) {
                throw new PerformanceException('Operation exceeded time limit');
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->auditLogger->logExecutionFailure($operation, $e);
            throw $e;
        }
    }

    private function validateResult(OperationResult $result): void 
    {
        if (!$this->validator->validateOutput($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function handleFailure(\Exception $e, CriticalOperation $operation): void 
    {
        $this->auditLogger->logOperationFailure($operation, $e);
        
        if ($e instanceof SecurityException) {
            $this->accessControl->incrementFailureCount();
            
            if ($this->accessControl->isThresholdExceeded()) {
                $this->auditLogger->logSecurityAlert($operation);
                $this->accessControl->blockAccess();
            }
        }
    }

    public function validateAccess(Request $request, string $resource): bool 
    {
        $startTime = microtime(true);
        
        try {
            $token = $this->validateToken($request->getToken());
            $user = $this->validateUser($token);
            
            if (!$this->accessControl->canAccess($user, $resource)) {
                $this->auditLogger->logUnauthorizedAccess($user, $resource);
                return false;
            }
            
            $this->auditLogger->logAuthorizedAccess($user, $resource);
            return true;
            
        } catch (\Exception $e) {
            $this->auditLogger->logAccessFailure($request, $e);
            return false;
        } finally {
            $executionTime = microtime(true) - $startTime;
            $this->auditLogger->logPerformanceMetric('access_validation', $executionTime);
        }
    }

    private function validateToken(string $token): Token 
    {
        if (!$this->encryption->verifyToken($token)) {
            throw new InvalidTokenException('Invalid security token');
        }
        return new Token($token);
    }

    private function validateUser(Token $token): User 
    {
        $user = $this->accessControl->getUserFromToken($token);
        
        if (!$user || !$user->isActive()) {
            throw new UserValidationException('Invalid or inactive user');
        }
        
        return $user;
    }
}
