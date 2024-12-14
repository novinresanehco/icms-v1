<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Services\{ValidationService, AuditService, CacheManager};
use Illuminate\Support\Facades\{DB, Log};
use App\Core\Exceptions\{SecurityException, ValidationException};

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $auditor;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $auditor,
        CacheManager $cache,
        array $config
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditor = $auditor;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function validateOperation(array $context, array $data): void 
    {
        DB::beginTransaction();
        
        try {
            // Pre-operation validation
            $this->validateRequest($data);
            $this->checkPermissions($context);
            $this->verifyIntegrity($data);
            
            // Log validation success
            $this->auditor->logValidation('operation_validation_success', $context);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log validation failure
            $this->auditor->logValidation('operation_validation_failure', [
                'context' => $context,
                'error' => $e->getMessage()
            ]);
            
            throw new SecurityException(
                'Operation validation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function executeSecureOperation(callable $operation, array $context): mixed
    {
        $operationId = $this->generateOperationId();
        
        // Start monitoring
        $this->auditor->startOperation($operationId, $context);
        
        try {
            // Operation execution with monitoring
            $result = $this->executeWithMonitoring($operation, $operationId);
            
            // Validate result
            $this->validateResult($result);
            
            // Log success
            $this->auditor->logSuccess($operationId, [
                'context' => $context,
                'result' => $result
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            // Handle failure with comprehensive logging
            $this->handleOperationFailure($e, $operationId, $context);
            throw $e;
        } finally {
            // Ensure operation is always marked as complete
            $this->auditor->endOperation($operationId);
        }
    }

    protected function executeWithMonitoring(callable $operation, string $operationId): mixed
    {
        return DB::transaction(function() use ($operation, $operationId) {
            // Monitor performance and resource usage
            $startTime = microtime(true);
            $startMemory = memory_get_usage();
            
            try {
                $result = $operation();
                
                // Record metrics
                $this->recordOperationMetrics($operationId, [
                    'execution_time' => microtime(true) - $startTime,
                    'memory_usage' => memory_get_usage() - $startMemory
                ]);
                
                return $result;
                
            } catch (\Exception $e) {
                // Log detailed failure metrics
                $this->recordOperationMetrics($operationId, [
                    'execution_time' => microtime(true) - $startTime,
                    'memory_usage' => memory_get_usage() - $startMemory,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        });
    }

    protected function validateRequest(array $data): void
    {
        $rules = $this->config['validation_rules'];
        
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Request validation failed');
        }
    }

    protected function checkPermissions(array $context): void
    {
        if (!isset($context['user']) || 
            !$this->validator->validatePermissions($context['user'], $context['required_permissions'] ?? [])) {
            throw new SecurityException('Permission denied');
        }
    }

    protected function verifyIntegrity(array $data): void
    {
        if (!$this->encryption->verifyIntegrity($data)) {
            throw new SecurityException('Data integrity check failed');
        }
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }
    }

    protected function handleOperationFailure(\Exception $e, string $operationId, array $context): void
    {
        // Log comprehensive failure details
        Log::error('Operation failed', [
            'operation_id' => $operationId,
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Record security event
        $this->auditor->logSecurityEvent('operation_failed', [
            'operation_id' => $operationId,
            'context' => $context,
            'error_type' => get_class($e)
        ]);

        // Clear any sensitive cached data
        $this->cache->clearOperationData($operationId);
    }

    protected function generateOperationId(): string
    {
        return uniqid('op_', true);
    }

    protected function recordOperationMetrics(string $operationId, array $metrics): void
    {
        $this->auditor->recordMetrics($operationId, $metrics);
    }
}
