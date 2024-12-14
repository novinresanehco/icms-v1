<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Contracts\{SecurityManagerInterface, ValidationInterface};
use App\Core\Services\{AuditService, EncryptionService};
use App\Core\Security\{AccessControl, SecurityContext};
use App\Core\Exceptions\{SecurityException, ValidationException};

class SecurityManager implements SecurityManagerInterface 
{
    private ValidationInterface $validator;
    private EncryptionService $encryption;
    private AuditService $audit;
    private AccessControl $access;

    public function __construct(
        ValidationInterface $validator,
        EncryptionService $encryption, 
        AuditService $audit,
        AccessControl $access
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->access = $access;
    }

    public function validateOperation(array $data, SecurityContext $context): bool
    {
        DB::beginTransaction();
        
        try {
            // Validate input data
            if (!$this->validator->validateInput($data)) {
                $this->audit->logValidationFailure($data, $context);
                throw new ValidationException('Invalid input data');
            }

            // Verify permissions
            if (!$this->access->hasPermission($context->user(), $context->requiredPermissions())) {
                $this->audit->logUnauthorizedAccess($context);
                throw new SecurityException('Insufficient permissions');
            }

            // Check rate limits
            if (!$this->access->checkRateLimit($context->user(), $context->operation())) {
                $this->audit->logRateLimitExceeded($context);
                throw new SecurityException('Rate limit exceeded');
            }

            // Encrypt sensitive data
            foreach ($data as $key => $value) {
                if ($this->isSensitive($key)) {
                    $data[$key] = $this->encryption->encrypt($value);
                }
            }

            DB::commit();
            $this->audit->logSuccessfulValidation($context);
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->audit->logException($e, $context);
            throw $e;
        }
    }

    public function executeSecureOperation(callable $operation, SecurityContext $context): mixed 
    {
        DB::beginTransaction();

        try {
            // Verify system state
            $this->verifySystemState();

            // Execute operation with monitoring
            $result = $operation();

            // Validate result
            if (!$this->validator->validateOutput($result)) {
                throw new ValidationException('Invalid operation result');
            }

            DB::commit();
            $this->audit->logSuccessfulOperation($context);
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, $context);
            throw $e;
        }
    }

    private function verifySystemState(): void
    {
        // Verify critical system components
        if (!$this->encryption->isOperational() || 
            !$this->access->isOperational() ||
            !$this->audit->isOperational()) {
            throw new SecurityException('Critical security systems non-operational');
        }

        // Check security configurations
        if (!$this->validateSecurityConfig()) {
            throw new SecurityException('Invalid security configuration');
        }
    }

    private function handleOperationFailure(\Exception $e, SecurityContext $context): void 
    {
        // Log detailed failure information
        $this->audit->logOperationFailure($e, $context, [
            'system_state' => $this->captureSystemState(),
            'error_trace' => $e->getTraceAsString()
        ]);

        // Execute emergency protocols if needed
        if ($this->isEmergencyScenario($e)) {
            $this->executeEmergencyProtocol($e, $context);
        }

        // Notify security team
        $this->notifySecurityTeam($e, $context);
    }

    private function validateSecurityConfig(): bool 
    {
        // Verify all required security settings
        $requiredSettings = [
            'encryption_algorithm',
            'key_rotation_interval',
            'max_failed_attempts',
            'session_timeout',
            'minimum_password_strength'
        ];

        foreach ($requiredSettings as $setting) {
            if (!config("security.$setting")) {
                return false;
            }
        }

        return true;
    }

    private function isSensitive(string $key): bool 
    {
        return in_array($key, [
            'password',
            'token',
            'secret',
            'key',
            'credentials'
        ]);
    }

    private function captureSystemState(): array 
    {
        return [
            'encryption_status' => $this->encryption->getStatus(),
            'access_control_status' => $this->access->getStatus(),
            'audit_status' => $this->audit->getStatus(),
            'memory_usage' => memory_get_usage(true),
            'system_load' => sys_getloadavg()
        ];
    }

    private function isEmergencyScenario(\Exception $e): bool 
    {
        return $e instanceof SecurityException && 
               $e->getSeverity() === SecurityException::SEVERITY_CRITICAL;
    }
}
