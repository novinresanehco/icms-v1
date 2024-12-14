<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Exceptions\{
    SecurityException,
    ValidationException,
    AuthorizationException
};
use Illuminate\Support\Facades\{Cache, Log, DB};

class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private AuthenticationService $auth;
    private MonitoringService $monitor;
    private AuditService $audit;
    private array $config;

    private const MAX_RETRY = 3;
    private const LOCK_TIMEOUT = 30;
    private const SECURITY_PREFIX = 'security:';

    public function __construct(
        ValidationService $validator,
        AuthenticationService $auth,
        MonitoringService $monitor,
        AuditService $audit,
        array $config
    ) {
        $this->validator = $validator;
        $this->auth = $auth;
        $this->monitor = $monitor;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function executeSecureOperation(callable $operation, array $context): mixed
    {
        $operationId = $this->generateOperationId();
        $this->monitor->startOperation($operationId);

        DB::beginTransaction();

        try {
            $this->validateSecurityContext($context);
            $this->enforceSecurityPolicy($context);
            $this->checkOperationLimits($context);

            $result = $this->executeWithProtection($operation, $context);

            $this->validateOperationResult($result);
            $this->auditOperation($operationId, $context, $result);

            DB::commit();
            $this->monitor->recordSuccess($operationId);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($operationId, $context, $e);
            throw new SecurityException(
                'Security operation failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    protected function validateSecurityContext(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid security context');
        }

        if (!$this->auth->validateSession($context)) {
            throw new SecurityException('Invalid security session');
        }

        if (!$this->checkPermissions($context)) {
            throw new AuthorizationException('Insufficient permissions');
        }
    }

    protected function enforceSecurityPolicy(array $context): void
    {
        if ($this->isRateLimitExceeded($context)) {
            throw new SecurityException('Operation rate limit exceeded');
        }

        if ($this->detectAnomalousPattern($context)) {
            throw new SecurityException('Anomalous operation pattern detected');
        }

        foreach ($this->config['security_checks'] as $check) {
            if (!$this->performSecurityCheck($check, $context)) {
                throw new SecurityException("Security check failed: {$check}");
            }
        }
    }

    protected function checkOperationLimits(array $context): void
    {
        if ($this->monitor->isQuotaExceeded($context)) {
            throw new SecurityException('Operation quota exceeded');
        }

        if ($this->monitor->isThresholdExceeded($context['operation'])) {
            throw new SecurityException('Operation threshold exceeded');
        }
    }

    protected function executeWithProtection(callable $operation, array $context): mixed
    {
        $attempts = 0;
        while ($attempts < self::MAX_RETRY) {
            try {
                $result = $this->monitor->track($context, function() use ($operation) {
                    return $operation();
                });

                if (!$this->validator->validateResult($result)) {
                    throw new ValidationException('Invalid operation result');
                }

                return $result;

            } catch (\Exception $e) {
                $attempts++;
                if ($attempts === self::MAX_RETRY) {
                    throw new SecurityException(
                        'Operation failed after ' . self::MAX_RETRY . ' attempts',
                        previous: $e
                    );
                }
                usleep(100000 * $attempts);
            }
        }
    }

    protected function validateOperationResult($result): void
    {
        if (!$this->validator->validateOperationResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }

        if (!$this->checkResultIntegrity($result)) {
            throw new SecurityException('Operation result integrity check failed');
        }
    }

    protected function auditOperation(string $operationId, array $context, $result): void
    {
        $this->audit->logOperation([
            'operation_id' => $operationId,
            'context' => $context,
            'result' => $result,
            'timestamp' => microtime(true),
            'node_id' => gethostname()
        ]);
    }

    protected function handleSecurityFailure(
        string $operationId,
        array $context,
        \Exception $e
    ): void {
        $this->monitor->recordFailure($operationId, $e);
        
        $this->audit->logSecurityEvent('security_failure', [
            'operation_id' => $operationId,
            'context' => $context,
            'error' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->monitor->captureSystemState()
        ]);

        if ($e instanceof SecurityException) {
            $this->executeEmergencyProtocol($e);
        }
    }

    protected function executeEmergencyProtocol(\Exception $e): void
    {
        try {
            $this->monitor->raiseSecurityAlert($e);
            $this->enforceSecurityLockdown();
            $this->notifySecurityTeam($e);
        } catch (\Exception $ex) {
            Log::critical('Emergency protocol failed', [
                'error' => $ex->getMessage(),
                'original_error' => $e->getMessage()
            ]);
        }
    }

    protected function isRateLimitExceeded(array $context): bool
    {
        $key = $this->getRateLimitKey($context);
        $limit = $this->config['rate_limits'][$context['operation']] ?? 0;
        
        $current = Cache::increment($key);
        if ($current === 1) {
            Cache::put($key, 1, now()->addMinutes(1));
        }
        
        return $current > $limit;
    }

    protected function detectAnomalousPattern(array $context): bool
    {
        return $this->monitor->detectAnomaly(
            $context['operation'],
            $this->getOperationPatterns($context)
        );
    }

    protected function checkResultIntegrity($result): bool
    {
        return hash_equals(
            $this->calculateResultHash($result),
            $this->validator->validateHash($result)
        );
    }

    protected function generateOperationId(): string
    {
        return uniqid(self::SECURITY_PREFIX, true);
    }

    protected function getRateLimitKey(array $context): string
    {
        return 'rate_limit:' . md5(serialize($context));
    }

    protected function getOperationPatterns(array $context): array
    {
        return $this->monitor->getPatterns(
            $context['operation'],
            $this->config['pattern_window']
        );
    }

    protected function calculateResultHash($result): string
    {
        return hash_hmac(
            'sha256',
            serialize($result),
            $this->config['integrity_key']
        );
    }
}
