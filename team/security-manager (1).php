<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Validation\ValidationService;
use App\Core\Encryption\EncryptionService;
use App\Core\Audit\AuditLogger;
use App\Core\Access\AccessControl;
use App\Exceptions\{SecurityException, ValidationException, UnauthorizedException};
use Illuminate\Support\Facades\DB;

class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    
    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
    }

    public function validateCriticalOperation(array $data, string $operation): void
    {
        DB::beginTransaction();
        
        try {
            // Input validation
            $this->validateInput($data, $operation);
            
            // Permission check
            $this->verifyPermission($operation);
            
            // Rate limiting
            $this->checkRateLimit($operation);
            
            // Data integrity verification
            $this->validateDataIntegrity($data);
            
            DB::commit();
            
            // Log successful validation
            $this->auditLogger->logValidation($operation, 'success');
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log validation failure
            $this->auditLogger->logValidation($operation, 'failure', [
                'error' => $e->getMessage(),
                'data' => $data
            ]);
            
            throw $e;
        }
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        $startTime = microtime(true);
        
        try {
            // Pre-operation security checks
            $this->validateContext($context);
            
            // Execute operation
            $result = $operation();
            
            // Verify operation result
            $this->validateResult($result);
            
            // Log successful operation
            $this->logOperationSuccess($context, $result, $startTime);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleOperationFailure($e, $context, $startTime);
            throw $e;
        }
    }

    protected function validateInput(array $data, string $operation): void 
    {
        if (!$this->validator->validate($data, $this->getRules($operation))) {
            throw new ValidationException('Input validation failed');
        }
    }
    
    protected function verifyPermission(string $operation): void
    {
        if (!$this->accessControl->hasPermission($operation)) {
            throw new UnauthorizedException('Insufficient permissions');
        }
    }
    
    protected function checkRateLimit(string $operation): void
    {
        if (!$this->accessControl->checkRateLimit($operation)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }
    
    protected function validateDataIntegrity(array $data): void
    {
        if (!$this->encryption->verifyIntegrity($data)) {
            throw new SecurityException('Data integrity validation failed');
        }
    }
    
    protected function validateContext(array $context): void
    {
        if (!isset($context['user_id'], $context['operation'])) {
            throw new ValidationException('Invalid operation context');
        }
    }
    
    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }
    
    protected function logOperationSuccess(array $context, $result, float $startTime): void
    {
        $executionTime = microtime(true) - $startTime;
        
        $this->auditLogger->logOperation(
            $context['operation'],
            'success',
            [
                'user_id' => $context['user_id'],
                'execution_time' => $executionTime,
                'result' => $result
            ]
        );
    }
    
    protected function handleOperationFailure(\Exception $e, array $context, float $startTime): void
    {
        $executionTime = microtime(true) - $startTime;
        
        $this->auditLogger->logOperation(
            $context['operation'],
            'failure',
            [
                'user_id' => $context['user_id'],
                'execution_time' => $executionTime,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        );
    }
    
    protected function getRules(string $operation): array
    {
        return config("validation.rules.{$operation}", []);
    }
}
