<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Interfaces\{SecurityManagerInterface, ValidationInterface, AuditInterface};
use App\Core\Exceptions\{SecurityException, ValidationException, UnauthorizedException};

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        SecurityConfig $config,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function validateRequest(SecurityContext $context): ValidationResult
    {
        $startTime = microtime(true);
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateInput($context);
            $this->checkPermissions($context);
            $this->verifyIntegrity($context);
            
            // Execute security checks
            $result = $this->executeSecurity($context);
            
            // Verify and audit
            $this->verifyResult($result);
            $this->auditSuccess($context, $result);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
            
        } finally {
            $this->recordMetrics('security_validation', microtime(true) - $startTime);
        }
    }

    private function validateInput(SecurityContext $context): void
    {
        if (!$this->validator->validateRequest($context->getRequest())) {
            throw new ValidationException('Invalid request format or content');
        }
    }

    private function checkPermissions(SecurityContext $context): void
    {
        if (!$this->accessControl->hasPermission($context)) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new UnauthorizedException('Insufficient permissions');
        }
    }

    private function verifyIntegrity(SecurityContext $context): void
    {
        if (!$this->encryption->verifyIntegrity($context->getData())) {
            throw new SecurityException('Data integrity check failed');
        }
    }

    private function executeSecurity(SecurityContext $context): ValidationResult
    {
        // Execute core security operations with monitoring
        return $this->monitorExecution(function() use ($context) {
            $this->performSecurityChecks($context);
            return new ValidationResult(true);
        });
    }

    private function performSecurityChecks(SecurityContext $context): void
    {
        // Rate limiting
        if (!$this->accessControl->checkRateLimit($context)) {
            throw new SecurityException('Rate limit exceeded');
        }

        // Additional security validations
        foreach ($this->config->getSecurityChecks() as $check) {
            if (!$this->validator->validateSecurityRule($check, $context)) {
                throw new SecurityException("Security check failed: {$check}");
            }
        }
    }

    private function handleFailure(\Exception $e, SecurityContext $context): void
    {
        // Log security failure with full context
        $this->auditLogger->logSecurityFailure($e, $context, [
            'timestamp' => now(),
            'trace' => $e->getTraceAsString(),
            'context' => $context->toArray(),
            'system_state' => $this->captureSystemState()
        ]);

        // Update security metrics
        $this->metrics->incrementFailureCount(
            $e instanceof SecurityException ? 'security' : 'validation'
        );

        // Notify security team if critical
        if ($this->isCriticalFailure($e)) {
            $this->notifySecurityTeam($e, $context);
        }
    }

    private function monitorExecution(callable $operation)
    {
        $monitor = $this->metrics->startOperation('security_check');
        
        try {
            return $operation();
        } finally {
            $this->metrics->endOperation($monitor);
        }
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'active_connections' => $this->metrics->getActiveConnections(),
            'cache_status' => Cache::getStatistics()
        ];
    }

    private function isCriticalFailure(\Exception $e): bool
    {
        return $e instanceof SecurityException || 
               $e->getCode() >= 500 || 
               str_contains(strtolower($e->getMessage()), ['critical', 'breach', 'attack']);
    }
}
