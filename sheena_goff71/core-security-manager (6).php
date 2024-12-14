<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{ValidationService, EncryptionService, AuditLogger};
use App\Core\Exceptions\{SecurityException, ValidationException};

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private array $securityConfig;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->securityConfig = $securityConfig;
    }

    /**
     * Executes a critical operation with comprehensive protection
     *
     * @throws SecurityException
     * @throws ValidationException
     */
    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        $startTime = microtime(true);

        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->monitorExecution($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(), 
                $e->getCode(), 
                $e
            );
        } finally {
            $this->recordMetrics($startTime);
        }
    }

    private function validateOperation(array $context): void
    {
        // Validate input data
        if (!$this->validator->validateInput($context)) {
            throw new ValidationException('Invalid operation context');
        }

        // Verify security constraints
        if (!$this->verifySecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }

        // Check rate limits
        if (!$this->checkRateLimits($context)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function monitorExecution(callable $operation, array $context): mixed
    {
        $monitor = new OperationMonitor($context);

        try {
            return $monitor->track(function() use ($operation) {
                return $operation();
            });
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function verifyResult($result): void
    {
        if (!$this->validator->verifyResultIntegrity($result)) {
            throw new SecurityException('Result integrity verification failed');
        }
    }

    private function handleFailure(\Exception $e, array $context): void
    {
        $this->auditLogger->logFailure($e, $context, [
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);

        if ($this->isSystemCritical($e)) {
            $this->triggerEmergencyProtocol($e, $context);
        }
    }

    private function verifySecurityConstraints(array $context): bool
    {
        return (
            $this->validateAuthentication($context) &&
            $this->validateAuthorization($context) &&
            $this->validateResourceAccess($context)
        );
    }

    private function checkRateLimits(array $context): bool
    {
        $key = $this->getRateLimitKey($context);
        return $this->isWithinLimits($key);
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg(),
            'db_connections' => DB::getConnections(),
            'cache_status' => $this->getCacheStatus()
        ];
    }

    private function isSystemCritical(\Exception $e): bool
    {
        return in_array($e->getCode(), $this->securityConfig['critical_error_codes']);
    }

    private function triggerEmergencyProtocol(\Exception $e, array $context): void
    {
        // Emergency notification and system protection measures
        $emergencyHandler = new EmergencyProtocolHandler();
        $emergencyHandler->handleCriticalFailure($e, $context);
    }

    private function recordMetrics(float $startTime): void
    {
        $metrics = [
            'execution_time' => microtime(true) - $startTime,
            'memory_peak' => memory_get_peak_usage(true),
            'timestamp' => time()
        ];

        MetricsCollector::record('security_operations', $metrics);
    }
}
