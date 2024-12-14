<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Contracts\SecurityInterface;
use App\Core\Security\Services\{
    ValidationService,
    EncryptionService,
    AuditService
};

class CoreSecurityManager implements SecurityInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $audit;
    private array $config;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $audit,
        array $config
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed 
    {
        // Pre-operation validation
        $this->validateOperation($context);

        DB::beginTransaction();
        $startTime = microtime(true);

        try {
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation, $context);

            // Validate result
            $this->validateResult($result);

            DB::commit();
            $this->logSuccess($context, $result, $startTime);

            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    protected function validateOperation(array $context): void 
    {
        // Input validation
        $this->validator->validateInput($context['input'] ?? [], $context['rules'] ?? []);

        // Access control
        if (!$this->validator->checkPermissions($context['user'], $context['resource'])) {
            throw new SecurityException('Insufficient permissions');
        }

        // Additional security checks
        $this->performSecurityCheck($context);
    }

    protected function executeWithMonitoring(callable $operation, array $context): mixed
    {
        $monitoringId = $this->startMonitoring($context);

        try {
            return $operation();
        } finally {
            $this->stopMonitoring($monitoringId);
        }
    }

    protected function validateResult(mixed $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Invalid operation result');
        }
    }

    protected function handleFailure(\Throwable $e, array $context): void
    {
        $this->audit->logFailure($e, $context);

        if ($e instanceof SecurityException) {
            $this->handleSecurityFailure($e, $context);
        }
    }

    protected function performSecurityCheck(array $context): void
    {
        // Rate limiting
        if (!$this->validator->checkRateLimit($context)) {
            throw new SecurityException('Rate limit exceeded');
        }

        // IP whitelist if configured
        if ($this->config['ip_whitelist'] ?? false) {
            if (!$this->validator->checkIpWhitelist($context['ip'])) {
                throw new SecurityException('IP not whitelisted');
            }
        }

        // Additional security requirements
        foreach ($context['security_requirements'] ?? [] as $requirement) {
            if (!$this->validator->checkSecurityRequirement($requirement)) {
                throw new SecurityException("Security requirement not met: $requirement");
            }
        }
    }

    private function startMonitoring(array $context): string
    {
        return $this->audit->startOperation($context);
    }

    private function stopMonitoring(string $id): void
    {
        $this->audit->stopOperation($id);
    }

    private function logSuccess(array $context, mixed $result, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->audit->logSuccess($context, $result, $duration);
    }

    private function handleSecurityFailure(SecurityException $e, array $context): void
    {
        // Enhanced security failure handling
        $this->audit->logSecurityIncident($e, $context);
        
        if ($e->isCritical()) {
            $this->notifySecurityTeam($e, $context);
        }
    }

    private function notifySecurityTeam(SecurityException $e, array $context): void
    {
        // Implementation depends on notification system
        // But must be handled without throwing exceptions
    }
}
