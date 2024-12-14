<?php
// Core Protection Layer
namespace App\Core\Protection;

abstract class CriticalOperation
{
    protected SecurityManager $security;
    protected ValidationService $validator;
    protected AuditLogger $logger;
    protected CacheManager $cache;

    final public function execute(array $data): OperationResult
    {
        DB::beginTransaction();
        
        try {
            $this->validatePreConditions($data);
            $result = $this->executeProtected($data);
            $this->validateResult($result);
            
            DB::commit();
            $this->logger->logSuccess($this->getOperationType(), $data, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $data);
            throw $e;
        }
    }

    protected function validatePreConditions(array $data): void
    {
        if (!$this->security->validateAccess($this->getRequiredPermissions())) {
            throw new SecurityException('Access denied');
        }

        if (!$this->validator->validate($data, $this->getValidationRules())) {
            throw new ValidationException('Invalid input data');
        }
    }

    abstract protected function executeProtected(array $data): OperationResult;
    abstract protected function getOperationType(): string;
    abstract protected function getRequiredPermissions(): array;
    abstract protected function getValidationRules(): array;
}

class SecurityManager implements SecurityManagerInterface
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

class ValidationService implements ValidationInterface
{
    private array $rules;
    private array $messages;

    public function validate(array $data, array $rules): bool
    {
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                throw new ValidationException($this->messages[$field] ?? 'Validation failed');
            }
        }
        return true;
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

class ContentManager
{
    private Repository $repository;
    private CacheManager $cache;
    private SecurityManager $security;
    private ValidationService $validator;

    public function create(array $data): Content
    {
        return $this->executeOperation(new CreateContentOperation($data));
    }

    public function update(int $id, array $data): Content
    {
        return $this->executeOperation(new UpdateContentOperation($id, $data));
    }

    public function delete(int $id): bool
    {
        return $this->executeOperation(new DeleteContentOperation($id));
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

class CreateContentOperation extends CriticalOperation
{
    protected function executeProtected(array $data): Content
    {
        $validated = $this->validator->validate($data, [
            'title' => 'required|string',
            'body' => 'required|string',
            'status' => 'required|in:draft,published'
        ]);

        return $this->repository->create($validated);
    }

    protected function getOperationType(): string
    {
        return 'create_content';
    }

    protected function getRequiredPermissions(): array
    {
        return ['content.create'];
    }
}

class Repository implements RepositoryInterface
{
    protected EntityManager $em;
    protected CacheManager $cache;
    protected ValidationService $validator;

    public function find(int $id): ?Entity
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->em->find($id)
        );
    }

    public function create(array $data): Entity
    {
        $this->validator->validate($data, $this->getCreationRules());
        $entity = $this->em->create($data);
        $this->cache->invalidatePrefix($this->getCachePrefix());
        return $entity;
    }

    abstract protected function getCachePrefix(): string;
    abstract protected function getCreationRules(): array;
}

class CacheManager
{
    private CacheStore $store;
    private int $ttl;
    private LoggerInterface $logger;

    public function remember(string $key, callable $callback): mixed
    {
        try {
            if ($cached = $this->get($key)) {
                return $cached;
            }

            $value = $callback();
            $this->set($key, $value);
            return $value;
            
        } catch (\Exception $e) {
            $this->logger->error('Cache operation failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function invalidatePrefix(string $prefix): void
    {
        $this->store->deleteByPrefix($prefix);
    }
}
