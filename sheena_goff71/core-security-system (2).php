<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityInterface;
use App\Core\Validation\ValidationService;
use App\Core\Protection\ProtectionService;
use App\Core\Monitoring\MonitoringService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SecurityManager implements SecurityInterface
{
    private ValidationService $validator;
    private ProtectionService $protection;
    private MonitoringService $monitor;
    private AuditService $audit;

    public function __construct(
        ValidationService $validator,
        ProtectionService $protection,
        MonitoringService $monitor,
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->protection = $protection;
        $this->monitor = $monitor;
        $this->audit = $audit;
    }

    public function validateOperation(Operation $operation): SecurityResult
    {
        $trackingId = $this->generateTrackingId();
        $this->monitor->startTracking($trackingId);

        DB::beginTransaction();

        try {
            // Pre-operation validation
            $this->validatePreOperation($operation);

            // Create protection checkpoint
            $protectionId = $this->protection->createProtectionPoint();

            // Execute security checks
            $result = $this->executeSecurityChecks($operation);

            // Validate result 
            $this->validateSecurityResult($result);

            // Log success
            $this->logSecuritySuccess($trackingId, $result);

            DB::commit();

            return new SecurityResult(
                success: true,
                trackingId: $trackingId,
                validationDetails: $result
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleSecurityFailure($trackingId, $operation, $e);
            throw new SecurityException(
                message: 'Security validation failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            $this->monitor->stopTracking($trackingId);
            $this->cleanup($trackingId, $protectionId ?? null);
        }
    }

    private function validatePreOperation(Operation $operation): void
    {
        if (!$this->validator->validateOperation($operation)) {
            throw new ValidationException('Operation validation failed');
        }

        if (!$this->validator->checkSecurityConstraints($operation)) {
            throw new SecurityConstraintException('Security constraints not met');
        }

        if (!$this->protection->validateSystemState()) {
            throw new SecurityStateException('System security state invalid');
        }
    }

    private function executeSecurityChecks(Operation $operation): array
    {
        return $this->monitor->track('security_validation', function() use ($operation) {
            $checks = [];

            // Authentication check
            $checks['auth'] = $this->validator->validateAuthentication($operation);
            
            // Authorization check  
            $checks['authz'] = $this->validator->validateAuthorization($operation);

            // Input validation
            $checks['input'] = $this->validator->validateInput($operation->getData());

            // Security scan
            $checks['scan'] = $this->protection->scanForThreats($operation);

            // Validate all checks passed
            foreach ($checks as $check => $result) {
                if (!$result->passed()) {
                    throw new SecurityCheckException("Security check failed: {$check}");
                }
            }

            return $checks;
        });
    }

    private function validateSecurityResult(array $result): void
    {
        if (!$this->validator->validateSecurityResult($result)) {
            throw new SecurityValidationException('Security result validation failed');
        }

        if (!$this->protection->verifySecurityState($result)) {
            throw new SecurityStateException('Security state verification failed');
        }
    }

    private function handleSecurityFailure(
        string $trackingId,
        Operation $operation,
        \Throwable $e
    ): void {
        // Log critical security failure
        Log::critical('Security failure occurred', [
            'tracking_id' => $trackingId,
            'operation' => $operation->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'security_state' => $this->protection->captureSecurityState()
        ]);

        // Record security incident
        $this->audit->recordSecurityIncident([
            'tracking_id' => $trackingId,
            'type' => 'security_failure',
            'severity' => 'critical',
            'details' => [
                'operation' => $operation->toArray(),
                'error' => $e->getMessage()
            ]
        ]);

        // Execute emergency protocols
        $this->protection->executeEmergencyProtocol($trackingId, $operation);
    }

    private function logSecuritySuccess(string $trackingId, array $result): void
    {
        $this->audit->recordSecurityEvent([
            'tracking_id' => $trackingId,
            'type' => 'security_validation',
            'status' => 'success',
            'details' => $result
        ]);
    }

    private function generateTrackingId(): string
    {
        return uniqid('sec_', true);
    }

    private function cleanup(string $trackingId, ?string $protectionId): void
    {
        try {
            if ($protectionId) {
                $this->protection->cleanupProtectionPoint($protectionId);
            }
            $this->monitor->cleanupTracking($trackingId);
        } catch (\Exception $e) {
            Log::warning('Security cleanup failed', [
                'tracking_id' => $trackingId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
