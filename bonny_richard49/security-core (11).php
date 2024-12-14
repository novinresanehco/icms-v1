<?php

namespace App\Core\Security;

class CriticalSecurityManager implements SecurityInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $audit;
    private MetricsCollector $metrics;

    public function executeCriticalOperation(Operation $operation): Result 
    {
        $operationId = $this->generateOperationId();
        
        DB::beginTransaction();
        $this->metrics->startOperation($operationId);

        try {
            $this->validateOperation($operation);
            
            $result = $this->executeWithProtection($operation);
            
            $this->validateResult($result);
            
            DB::commit();
            
            $this->audit->logSuccess($operationId, $operation);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operationId, $operation);
            throw $e;
            
        } finally {
            $this->metrics->endOperation($operationId);
        }
    }

    private function validateOperation(Operation $operation): void
    {
        if (!$this->validator->validateOperation($operation)) {
            throw new ValidationException('Invalid operation');
        }

        if (!$this->validator->validatePermissions($operation->getRequiredPermissions())) {
            throw new AuthorizationException('Insufficient permissions');
        }
    }

    private function executeWithProtection(Operation $operation): Result
    {
        $result = $operation->execute();

        if (!$this->validator->validateIntegrity($result)) {
            throw new IntegrityException('Operation result integrity check failed');
        }

        return $result;
    }

    private function validateResult(Result $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function handleFailure(\Exception $e, string $operationId, Operation $operation): void
    {
        $this->audit->logFailure($operationId, $operation, $e);
        
        if ($e instanceof SecurityException) {
            $this->handleSecurityFailure($e);
        }
    }

    private function generateOperationId(): string
    {
        return uniqid('op_', true);
    }
}

class ValidationService implements ValidationInterface
{
    private array $rules;
    private array $validators;

    public function validateOperation(Operation $operation): bool
    {
        foreach ($this->rules['operation'] as $rule) {
            if (!$this->validators[$rule]->validate($operation)) {
                return false;
            }
        }
        return true;
    }

    public function validateResult(Result $result): bool
    {
        foreach ($this->rules['result'] as $rule) {
            if (!$this->validators[$rule]->validate($result)) {
                return false;
            }
        }
        return true;
    }

    public function validateIntegrity($data): bool
    {
        foreach ($this->rules['integrity'] as $rule) {
            if (!$this->validators[$rule]->validate($data)) {
                return false;
            }
        }
        return true;
    }
}

class EncryptionService implements EncryptionInterface 
{
    private string $key;
    private string $cipher;

    public function encrypt(string $data): string
    {
        return openssl_encrypt($data, $this->cipher, $this->key, 0, $this->iv());
    }

    public function decrypt(string $data): string
    {
        return openssl_decrypt($data, $this->cipher, $this->key, 0, $this->iv());
    }

    public function hash(string $data): string
    {
        return hash_hmac('sha256', $data, $this->key);
    }

    private function iv(): string
    {
        return random_bytes(openssl_cipher_iv_length($this->cipher));
    }
}

class AuditLogger implements AuditInterface 
{
    private LoggerInterface $logger;
    private MetricsCollector $metrics;

    public function logSuccess(string $operationId, Operation $operation): void
    {
        $this->logger->info('Operation succeeded', [
            'operation_id' => $operationId,
            'type' => get_class($operation),
            'timestamp' => time(),
            'metrics' => $this->metrics->collect($operationId)
        ]);
    }

    public function logFailure(string $operationId, Operation $operation, \Exception $e): void
    {
        $this->logger->error('Operation failed', [
            'operation_id' => $operationId,
            'type' => get_class($operation),
            'error' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'trace' => $e->getTraceAsString()
            ],
            'timestamp' => time(),
            'metrics' => $this->metrics->collect($operationId)
        ]);
    }
}

class MetricsCollector implements MetricsInterface
{
    private array $metrics = [];

    public function startOperation(string $operationId): void
    {
        $this->metrics[$operationId] = [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true)
        ];
    }

    public function endOperation(string $operationId): void
    {
        $this->metrics[$operationId]['end_time'] = microtime(true);
        $this->metrics[$operationId]['memory_end'] = memory_get_usage(true);
        $this->metrics[$operationId]['duration'] = 
            $this->metrics[$operationId]['end_time'] - 
            $this->metrics[$operationId]['start_time'];
    }

    public function collect(string $operationId): array
    {
        return $this->metrics[$operationId] ?? [];
    }
}
