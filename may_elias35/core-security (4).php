<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Security\Services\{
    AuthenticationService,
    AuthorizationService,
    EncryptionService,
    ValidationService
};
use App\Core\Logging\AuditLogger;
use Illuminate\Support\Facades\DB;

class SecurityManager implements SecurityManagerInterface 
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function __construct(
        AuthenticationService $auth,
        AuthorizationService $authz,
        EncryptionService $encryption,
        ValidationService $validator,
        AuditLogger $logger
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->logger = $logger;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        // Begin transaction and validation
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Post-execution verification
            $this->verifyResult($result);
            
            DB::commit();
            $this->logger->logSuccess('Critical operation completed', $context);
            
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    private function validateOperation(array $context): void 
    {
        // Validate authentication
        if (!$this->auth->validate($context['auth'] ?? null)) {
            throw new SecurityException('Authentication failed');
        }

        // Check authorization
        if (!$this->authz->isAuthorized($context['permissions'] ?? [])) {
            throw new SecurityException('Unauthorized operation');
        }

        // Validate input data
        if (!$this->validator->validateInput($context['data'] ?? [])) {
            throw new ValidationException('Invalid input data');
        }
    }

    private function executeWithProtection(callable $operation, array $context): mixed
    {
        // Create execution context with encryption
        $secureContext = $this->encryption->secureContext($context);
        
        // Execute operation with monitoring
        return $operation($secureContext);
    }

    private function verifyResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Operation result validation failed');
        }
    }

    private function handleFailure(\Exception $e, array $context): void
    {
        $this->logger->logError('Critical operation failed', [
            'error' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class SecurityException extends \Exception {}
class ValidationException extends \Exception {}
