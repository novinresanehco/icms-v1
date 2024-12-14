<?php

namespace App\Core\Security;

class SecurityManager
{
    private ValidationService $validator;
    private AuthManager $auth;
    private AuditLogger $audit;
    private SecurityConfig $config;

    public function executeCriticalOperation(Operation $operation): Result 
    {
        DB::beginTransaction();
        
        try {
            $this->validateOperation($operation);
            
            $result = $this->executeWithProtection($operation);
            
            $this->validateResult($result);
            
            DB::commit();
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollback();
            $this->handleFailure($operation, $e);
            throw new SecurityException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function validateOperation(Operation $operation): void
    {
        if (!$this->validator->validate($operation)) {
            throw new ValidationException('Invalid operation');
        }

        if (!$this->auth->checkPermission($operation->getRequiredPermission())) {
            throw new AuthorizationException('Insufficient permissions');
        }
    }

    private function executeWithProtection(Operation $operation): Result
    {
        $monitor = new OperationMonitor($operation);
        return $monitor->execute(fn() => $operation->execute());
    }

    private function validateResult(Result $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid result');
        }
    }

    private function handleFailure(Operation $operation, \Exception $e): void
    {
        $this->audit->logFailure($operation, $e);
    }
}

namespace App\Core\Data;

class DataManager 
{
    private Repository $repository;
    private SecurityManager $security;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function store(array $data): Result
    {
        $operation = new StoreOperation($data);
        
        return $this->security->executeCriticalOperation($operation);
    }

    public function retrieve(string $id): Result
    {
        return $this->cache->remember($id, function() use ($id) {
            $operation = new RetrieveOperation($id);
            return $this->security->executeCriticalOperation($operation);
        });
    }

    public function update(string $id, array $data): Result
    {
        $operation = new UpdateOperation($id, $data);
        
        $result = $this->security->executeCriticalOperation($operation);
        
        $this->cache->forget($id);
        
        return $result;
    }

    public function delete(string $id): Result
    {
        $operation = new DeleteOperation($id);
        
        $result = $this->security->executeCriticalOperation($operation);
        
        $this->cache->forget($id);
        
        return $result;
    }
}

namespace App\Core\Content;

class ContentManager extends DataManager
{
    public function createContent(array $data): Content
    {
        $this->validateContentData($data);
        
        return $this->store($data);
    }

    public function updateContent(string $id, array $data): Content 
    {
        $this->validateContentData($data);
        
        return $this->update($id, $data);
    }

    public function publishContent(string $id): bool
    {
        $operation = new PublishOperation($id);
        
        return $this->security->executeCriticalOperation($operation);
    }

    private function validateContentData(array $data): void
    {
        $validator = new ContentValidator($data);
        
        if (!$validator->validate()) {
            throw new ValidationException($validator->getErrors());
        }
    }
}

namespace App\Core\Validation;

class ValidationManager
{
    private array $rules;
    private array $messages;

    public function validate($data, array $rules = null): bool
    {
        $rules = $rules ?? $this->rules;
        
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field], $rule)) {
                throw new ValidationException($this->messages[$field]);
            }
        }
        
        return true;
    }

    private function validateField($value, $rule): bool
    {
        return match($rule) {
            'required' => !empty($value),
            'numeric' => is_numeric($value),
            'string' => is_string($value),
            default => true
        };
    }
}

namespace App\Core\Cache;

class CacheManager
{
    private CacheStore $store;
    private SecurityManager $security;
    private int $ttl;

    public function remember(string $key, callable $callback)
    {
        if ($cached = $this->store->get($key)) {
            return $cached;
        }
        
        $value = $callback();
        
        $this->store->put($key, $value, $this->ttl);
        
        return $value;
    }

    public function forget(string $key): void
    {
        $this->store->forget($key);
    }
}

namespace App\Core\Audit;

class AuditLogger
{
    private LoggerInterface $logger;
    private SecurityConfig $config;

    public function logOperation(Operation $operation): void
    {
        $this->logger->info('Operation executed', [
            'operation' => get_class($operation),
            'user' => $this->getCurrentUser(),
            'timestamp' => time()
        ]);
    }

    public function logFailure(Operation $operation, \Exception $e): void
    {
        $this->logger->error('Operation failed', [
            'operation' => get_class($operation),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'user' => $this->getCurrentUser(),
            'timestamp' => time()
        ]);
    }

    private function getCurrentUser(): ?string
    {
        return Auth::user()?->id;
    }
}