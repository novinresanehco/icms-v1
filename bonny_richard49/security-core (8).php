namespace App\Core\Security;

abstract class CriticalOperation
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditLogger $logger;
    private BackupService $backup;

    final public function execute(array $data): OperationResult 
    {
        $backupId = $this->backup->createPoint();
        
        DB::beginTransaction();
        
        try {
            $this->validateOperation($data);
            $result = $this->executeInternal($data);
            $this->validateResult($result);
            
            DB::commit();
            $this->logger->logSuccess($this->getOperationType(), $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->backup->restore($backupId);
            $this->handleFailure($e);
            throw $e;
        }
    }

    private function validateOperation(array $data): void 
    {
        $this->security->validateAccess($this->getRequiredPermissions());
        $this->validator->validate($data, $this->getValidationRules());
        $this->security->validateDataIntegrity($data);
    }

    private function validateResult(OperationResult $result): void 
    {
        $this->validator->validateResult($result, $this->getResultRules());
        $this->security->validateResultIntegrity($result);
    }

    private function handleFailure(\Exception $e): void 
    {
        $this->logger->logFailure($this->getOperationType(), $e);
        $this->security->handleSecurityException($e);
    }

    abstract protected function executeInternal(array $data): OperationResult;
    abstract protected function getOperationType(): string;
    abstract protected function getRequiredPermissions(): array;
    abstract protected function getValidationRules(): array;
    abstract protected function getResultRules(): array;
}

class SecurityManager implements SecurityInterface
{
    private AuthenticationService $auth;
    private AccessControl $access;
    private EncryptionService $encryption;
    private AuditLogger $logger;
    private SecurityConfig $config;

    public function validateAccess(array $permissions): void 
    {
        $context = $this->auth->getCurrentContext();
        
        if (!$context || !$context->isValid()) {
            $this->logger->logSecurityAlert('Invalid security context');
            throw new SecurityException('Invalid security context');
        }

        if (!$this->access->hasPermissions($context->getUser(), $permissions)) {
            $this->logger->logAccessDenied($context->getUser(), $permissions);
            throw new AccessDeniedException('Insufficient permissions');
        }
    }

    public function validateDataIntegrity(array $data): void 
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitiveField($key)) {
                if (!$this->encryption->verifyIntegrity($value)) {
                    throw new IntegrityException("Data integrity violation for $key");
                }
            }
        }
    }

    public function handleSecurityException(\Exception $e): void 
    {
        if ($this->isSecurityCritical($e)) {
            $this->logger->logCriticalSecurity($e);
            $this->notifySecurityTeam($e);
        }
    }

    private function isSecurityCritical(\Exception $e): bool 
    {
        return $e instanceof SecurityException || 
               $e instanceof AuthenticationException ||
               $e instanceof IntegrityException;
    }

    private function isSensitiveField(string $field): bool 
    {
        return in_array($field, $this->config->getSensitiveFields());
    }
}

class ValidationService implements ValidationInterface
{
    private array $rules;
    private array $messages;

    public function validate(array $data, array $rules): void 
    {
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                throw new ValidationException(
                    $this->messages[$field] ?? "Validation failed for $field"
                );
            }
        }
    }

    public function validateResult(OperationResult $result, array $rules): void 
    {
        if (!$this->validateResultData($result->getData(), $rules)) {
            throw new ValidationException('Result validation failed');
        }
    }

    private function validateField($value, string $rule): bool 
    {
        return match($rule) {
            'required' => !empty($value),
            'numeric' => is_numeric($value),
            'string' => is_string($value),
            default => true
        };
    }
}

class EncryptionService implements EncryptionInterface
{
    private string $key;
    private string $cipher;

    public function encrypt(string $data): string 
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, $this->cipher, $this->key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public function decrypt(string $data): string 
    {
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, $this->cipher, $this->key, 0, $iv);
    }

    public function verifyIntegrity(string $data): bool 
    {
        try {
            return $this->decrypt($data) !== false;
        } catch (\Exception $e) {
            return false;
        }
    }
}
