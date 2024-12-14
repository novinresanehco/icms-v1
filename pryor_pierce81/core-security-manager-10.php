<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use Illuminate\Support\Facades\Log;

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

    public function validateRequest(Request $request): SecurityValidationResult 
    {
        try {
            // Validate input
            $validatedData = $this->validator->validateInput($request->all());
            
            // Authenticate user
            $user = $this->auth->authenticate($request);
            if (!$user) {
                throw new AuthenticationException('Invalid credentials');
            }
            
            // Check authorization
            if (!$this->authz->isAuthorized($user, $request->getAction())) {
                throw new AuthorizationException('Unauthorized action');
            }
            
            // Log successful access
            $this->audit->logAccess($user, $request);
            
            return new SecurityValidationResult(true, $user, $validatedData);
            
        } catch (SecurityException $e) {
            // Log security incident
            $this->audit->logSecurityIncident($e, $request);
            Log::error('Security validation failed', [
                'exception' => $e,
                'request' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function validateOperation(string $operation, array $data): OperationValidationResult
    {
        DB::beginTransaction();
        
        try {
            // Validate operation data
            $validatedData = $this->validator->validateOperationData($operation, $data);
            
            // Check operation permissions
            if (!$this->authz->canPerformOperation($operation)) {
                throw new AuthorizationException('Operation not permitted');
            }
            
            // Log operation attempt
            $this->audit->logOperationAttempt($operation, $data);
            
            DB::commit();
            return new OperationValidationResult(true, $validatedData);
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationError($e, $operation, $data);
            throw $e;
        }
    }

    protected function handleOperationError(\Exception $e, string $operation, array $data): void
    {
        $this->audit->logOperationFailure($operation, $data, $e);
        Log::error('Operation validation failed', [
            'operation' => $operation,
            'data' => $data,
            'exception' => $e,
            'trace' => $e->getTraceAsString()
        ]);
    }
}
