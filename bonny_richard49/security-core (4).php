<?php
namespace App\Core\Security;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;

    public function executeCriticalOperation(Operation $operation): Result
    {
        DB::beginTransaction();
        
        try {
            $this->validateOperation($operation);
            $result = $this->executeWithProtection($operation);
            $this->verifyResult($result);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollback();
            $this->handleFailure($e);
            throw $e;
        }
    }

    private function validateOperation(Operation $operation): void
    {
        if (!$this->validator->validateRequest($operation->getRequest())) {
            throw new ValidationException('Invalid request');
        }

        if (!$this->accessControl->checkPermission($operation->getContext())) {
            throw new AccessDeniedException();
        }

        if (!$this->encryption->verifyIntegrity($operation->getData())) {
            throw new IntegrityException();
        }
    }

    private function executeWithProtection(Operation $operation): Result 
    {
        $backupPoint = $this->createBackupPoint();
        
        try {
            $result = $operation->execute();
            $this->verifyExecutionResult($result);
            return $result;
        } catch (\Exception $e) {
            $this->restoreBackupPoint($backupPoint);
            throw $e;
        }
    }

    private function verifyResult(Result $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function handleFailure(\Exception $e): void
    {
        $this->auditLogger->logFailure($e);
        $this->notifySecurityTeam($e);
    }
}

class ValidationService
{
    private array $rules;

    public function validateRequest(Request $request): bool
    {
        foreach ($this->rules as $rule) {
            if (!$rule->validate($request)) {
                return false;
            }
        }
        return true; 
    }

    public function validateResult(Result $result): bool
    {
        return $result->isValid() && $this->validateResultData($result->getData());
    }
}

class EncryptionService 
{
    private string $key;
    private string $algorithm;

    public function encrypt(string $data): string
    {
        return openssl_encrypt($data, $this->algorithm, $this->key);
    }

    public function decrypt(string $encrypted): string
    {
        return openssl_decrypt($encrypted, $this->algorithm, $this->key);
    }

    public function verifyIntegrity(array $data): bool
    {
        return hash_equals(
            $data['hash'],
            hash_hmac('sha256', $data['content'], $this->key)
        );
    }
}
