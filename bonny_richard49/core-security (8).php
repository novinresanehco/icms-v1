<?php
namespace App\Core\Security;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private AuditService $audit;
    private ValidationService $validator;
    
    public function __construct(
        AuthenticationService $auth,
        AuthorizationService $authz, 
        AuditService $audit,
        ValidationService $validator
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->audit = $audit;
        $this->validator = $validator;
    }

    public function validateAccess(SecurityContext $context): ValidationResult 
    {
        DB::beginTransaction();
        try {
            // Validate authentication
            $user = $this->auth->validate($context);
            
            // Check authorization
            if (!$this->authz->hasPermission($user, $context->getRequiredPermission())) {
                throw new UnauthorizedException();
            }
            
            // Validate input if present
            if ($data = $context->getData()) {
                $this->validator->validate($data, $context->getValidationRules());
            }
            
            // Log successful access 
            $this->audit->logAccess($user, $context);
            
            DB::commit();
            return new ValidationResult(true);

        } catch (Exception $e) {
            DB::rollBack();
            $this->audit->logFailure($e, $context);
            throw $e;
        }
    }

    public function executeSecured(callable $operation, SecurityContext $context): mixed
    {
        // Validate access first
        $this->validateAccess($context);
        
        DB::beginTransaction();
        try {
            // Execute operation with monitoring
            $result = $operation();
            
            // Audit successful operation
            $this->audit->logOperation($context, $result);
            
            DB::commit();
            return $result;

        } catch (Exception $e) {
            DB::rollBack();
            $this->audit->logFailure($e, $context);
            throw new SecurityException('Operation failed: ' . $e->getMessage());
        }
    }
}
