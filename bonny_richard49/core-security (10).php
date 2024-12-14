<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{ValidationService, EncryptionService, AuditService};
use App\Core\Security\{AccessControl, SecurityConfig};
use App\Core\Monitoring\MetricsCollector;
use App\Core\Exceptions\{SecurityException, ValidationException};
use Illuminate\Support\Facades\DB;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $audit;
    private AccessControl $accessControl;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $audit,
        AccessControl $accessControl,
        SecurityConfig $config,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->accessControl = $accessControl;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function validateCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        $startTime = microtime(true);
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperationRequest($operation, $context);
            
            // Execute with monitoring
            $result = $this->executeSecurely($operation, $context);
            
            // Post-execution validation
            $this->validateOperationResult($result);
            
            DB::commit();
            $this->audit->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleOperationFailure($operation, $context, $e);
            throw $e;
            
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function validateOperationRequest(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Input validation
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid operation input');
        }

        // Authorization check
        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            throw new SecurityException('Insufficient permissions');
        }

        // Rate limiting
        if (!$this->accessControl->checkRateLimit($context)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function executeSecurely(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        $monitor = new OperationMonitor($operation, $context);
        
        return $monitor->execute(function() use ($operation) {
            $result = $operation->execute();
            
            if (!$result->isValid()) {
                throw new SecurityException('Operation produced invalid result');
            }
            
            return $result;
        });
    }

    private function validateOperationResult(OperationResult $result): void {
        if (!$this->validator->validateOutput($result->getData())) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    private function handleOperationFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Throwable $e
    ): void {
        // Log failure with full context
        $this->audit->logFailure(
            $operation,
            $context,
            $e,
            $this->gatherFailureContext($e)
        );

        // Alert if critical
        if ($this->isCriticalFailure($e)) {
            $this->alertSecurityTeam($operation, $context, $e);
        }
    }

    private function recordMetrics(CriticalOperation $operation, float $duration): void {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'duration' => $duration,
            'memory_peak' => memory_get_peak_usage(true),
            'timestamp' => time()
        ]);
    }

    private function gatherFailureContext(\Throwable $e): array {
        return [
            'trace' => $e->getTraceAsString(),
            'memory_usage' => memory_get_usage(true),
            'system_load' => sys_getloadavg(),
            'timestamp' => microtime(true)
        ];
    }

    private function isCriticalFailure(\Throwable $e): bool {
        return $e instanceof SecurityException || 
               $e->getCode() >= $this->config->getCriticalErrorCode();
    }

    private function alertSecurityTeam(
        CriticalOperation $operation,
        SecurityContext $context,
        \Throwable $e
    ): void {
        // Implementation would send immediate alerts to security team
        // through configured channels (email, SMS, Slack, etc.)
    }
}
