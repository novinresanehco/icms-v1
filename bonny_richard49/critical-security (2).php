namespace App\Core;

abstract class CriticalOperation
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditLogger $logger;
    protected BackupService $backup;

    final public function execute(array $data): OperationResult 
    {
        $backupId = $this->backup->createPoint();
        DB::beginTransaction();
        
        try {
            $this->validateOperation($data);
            $result = $this->executeProtected($data);
            $this->validateResult($result);
            
            DB::commit();
            $this->logger->logSuccess($this->getOperationType(), $data, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->backup->restore($backupId);
            $this->handleFailure($e, $data);
            throw $e;
        }
    }

    protected function validateOperation(array $data): void 
    {
        if (!$this->security->validateAccess($this->getRequiredPermissions())) {
            throw new SecurityException('Access denied');
        }

        if (!$this->validator->validate($data, $this->getValidationRules())) {
            throw new ValidationException('Invalid input data');
        }

        if (!$this->security->validateIntegrity($data)) {
            throw new SecurityException('Data integrity violation');
        }
    }

    abstract protected function executeProtected(array $data): OperationResult;
    abstract protected function getOperationType(): string;
    abstract protected function getRequiredPermissions(): array;
    abstract protected function getValidationRules(): array;
}

class SecurityManager
{
    private AuthenticationService $auth;
    private AccessControl $access;
    private ValidationService $validator;
    private AuditLogger $logger;
    private EncryptionService $encryption;

    public function validateAccess(array $permissions): bool
    {
        $context = $this->auth->getCurrentContext();
        
        if (!$context) {
            $this->logger->logSecurityAlert('No security context');
            return false;
        }

        if (!$this->access->hasPermissions($context->getUser(), $permissions)) {
            $this->logger->logAccessDenied($context->getUser(), $permissions);
            return false;
        }

        return true;
    }

    public function validateRequest(Request $request): void
    {
        if (!$this->validator->validate($request)) {
            $this->logger->logValidationFailure($request);
            throw new ValidationException();
        }

        if (!$this->validator->validateSecurity($request)) {
            $this->logger->logSecurityFailure($request);
            throw new SecurityException();
        }
    }

    public function encryptSensitiveData(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitiveField($key)) {
                $data[$key] = $this->encryption->encrypt($value);
            }
        }
        return $data;
    }

    private function isSensitiveField(string $field): bool
    {
        return in_array($field, ['password', 'token', 'secret']);
    }
}

class ValidationService
{
    private array $rules;
    private array $messages;

    public function validate(array $data, array $rules): bool
    {
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                throw new ValidationException(
                    $this->messages[$field] ?? 'Validation failed'
                );
            }
        }
        return true;
    }

    private function validateField($value, string $rule): bool
    {
        return match ($rule) {
            'required' => !empty($value),
            'numeric' => is_numeric($value),
            'string' => is_string($value),
            default => true
        };
    }
}

class ContentManager
{
    private Repository $repository;
    private CacheManager $cache;
    private SecurityManager $security;
    private ValidationService $validator;

    public function create(array $data): Content
    {
        return $this->executeOperation(new CreateContentOperation(
            $this->repository,
            $this->validator,
            $data
        ));
    }

    public function update(int $id, array $data): Content
    {
        return $this->executeOperation(new UpdateContentOperation(
            $this->repository,
            $this->validator,
            $id,
            $data
        ));
    }

    private function executeOperation(CriticalOperation $operation): mixed
    {
        $this->security->validateAccess($operation->getRequiredPermissions());
        
        return DB::transaction(function() use ($operation) {
            $result = $operation->execute();
            $this->cache->invalidatePrefix('content');
            return $result;
        });
    }
}

class Repository
{
    protected DatabaseManager $db;
    protected CacheManager $cache;
    protected ValidationService $validator;

    public function find(int $id): ?Entity
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->db->find($id)
        );
    }

    public function create(array $data): Entity
    {
        $this->validator->validate($data, $this->getCreationRules());
        $entity = $this->db->create($data);
        $this->cache->invalidatePrefix($this->getCachePrefix());
        return $entity;
    }

    abstract protected function getCachePrefix(): string;
    abstract protected function getCreationRules(): array;
}
