<?php

namespace App\Core\Security;

use App\Core\Contracts\{SecurityManagerInterface, ValidationInterface};
use App\Core\Services\{AuditService, EncryptionService};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Core security manager handling critical system protection requirements.
 * CRITICAL: Any modifications require security team approval.
 */
class SecurityManager implements SecurityManagerInterface 
{
    private ValidationInterface $validator;
    private EncryptionService $encryption;
    private AuditService $audit;
    private array $config;

    public function __construct(
        ValidationInterface $validator,
        EncryptionService $encryption,
        AuditService $audit,
        array $config
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->config = $config;
    }

    /**
     * Execute critical operation with comprehensive protection
     *
     * @throws SecurityException if validation or execution fails
     */
    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation);
            
            // Verify result integrity
            $this->validateResult($result);
            
            DB::commit();
            $this->audit->logSuccess($context);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Validate operation pre-execution
     */
    protected function validateOperation(array $context): void
    {
        // Validate input data
        $this->validator->validateInput($context['data'] ?? []);

        // Check permissions
        if (!$this->checkPermissions($context)) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Verify request integrity
        if (!$this->encryption->verifyIntegrity($context)) {
            throw new IntegrityException('Request integrity check failed');
        }
    }

    /**
     * Execute operation with monitoring
     */
    protected function executeWithProtection(callable $operation): mixed
    {
        // Create monitoring context
        $monitor = new OperationMonitor();
        
        try {
            // Execute with monitoring
            return $monitor->track($operation);
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Validate operation result
     */
    protected function validateResult($result): void
    {
        if (!$this->validator->validateOutput($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    /**
     * Handle operation failure with comprehensive logging
     */
    protected function handleFailure(\Exception $e, array $context): void
    {
        // Log detailed failure information
        $this->audit->logFailure($e, $context, [
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);

        // Notify security team for critical failures
        if ($this->isCriticalFailure($e)) {
            $this->notifySecurityTeam($e, $context);
        }
    }

    /**
     * Capture current system state for diagnostics
     */
    protected function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'system_load' => sys_getloadavg(),
            'active_connections' => DB::connection()->getDatabaseName()
        ];
    }

    /**
     * Verify if user has required permissions
     */
    protected function checkPermissions(array $context): bool
    {
        return isset($context['user']) && 
               isset($context['required_permissions']) &&
               $this->validator->validatePermissions(
                   $context['user'],
                   $context['required_permissions']
               );
    }

    /**
     * Determine if failure requires immediate security team notification
     */
    protected function isCriticalFailure(\Exception $e): bool
    {
        return $e instanceof SecurityException ||
               $e instanceof IntegrityException ||
               $e->getCode() === $this->config['critical_error_code'];
    }

    /**
     * Notify security team of critical failures
     */
    protected function notifySecurityTeam(\Exception $e, array $context): void
    {
        Log::critical('Security incident detected', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'system_state' => $this->captureSystemState()
        ]);
    }
}
