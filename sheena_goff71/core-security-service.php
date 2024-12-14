<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Interfaces\{
    SecurityManagerInterface,
    ValidationServiceInterface,
    AuditServiceInterface
};
use App\Core\Exceptions\{
    SecurityException,
    ValidationException,
    AuthorizationException
};

class SecurityManager implements SecurityManagerInterface
{
    private ValidationServiceInterface $validator;
    private AuditServiceInterface $audit;
    private array $securityConfig;

    public function __construct(
        ValidationServiceInterface $validator,
        AuditServiceInterface $audit,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->audit = $audit;
        $this->securityConfig = $securityConfig;
    }

    public function executeSecureOperation(callable $operation, array $context): mixed
    {
        // Start transaction and monitoring
        DB::beginTransaction();
        $operationId = $this->audit->startOperation($context);

        try {
            // Validate operation context
            $this->validateOperationContext($context);
            
            // Execute operation with monitoring
            $startTime = microtime(true);
            $result = $operation();
            $executionTime = microtime(true) - $startTime;

            // Verify result
            $this->validateOperationResult($result);
            
            // Log successful operation
            $this->audit->logSuccess($operationId, $context, $executionTime);
            
            DB::commit();
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Log failure with full context
            $this->audit->logFailure(
                $operationId,
                $e,
                $context,
                $this->getCriticalData()
            );

            throw new SecurityException(
                'Security violation in protected operation', 
                previous: $e
            );
        }
    }

    protected function validateOperationContext(array $context): void
    {
        // Validate authentication
        if (!$this->validator->validateAuthentication($context)) {
            throw new AuthorizationException('Invalid authentication');
        }

        // Validate authorization
        if (!$this->validator->validateAuthorization($context)) {
            throw new AuthorizationException('Unauthorized operation');
        }

        // Validate input data
        if (!$this->validator->validateInput($context['data'] ?? [])) {
            throw new ValidationException('Invalid input data');
        }

        // Additional security checks
        $this->performSecurityChecks($context);
    }

    protected function validateOperationResult($result): void 
    {
        if (!$this->validator->validateOutput($result)) {
            throw new ValidationException('Invalid operation result');
        }

        $this->verifyResultSecurity($result);
    }

    protected function performSecurityChecks(array $context): void
    {
        // Rate limiting
        if (!$this->checkRateLimit($context)) {
            throw new SecurityException('Rate limit exceeded');
        }

        // IP validation if required
        if ($this->securityConfig['ip_validation'] ?? false) {
            $this->validateIpAddress($context['ip'] ?? null);
        }

        // Session validation
        if (!$this->validateSession($context['session'] ?? null)) {
            throw new SecurityException('Invalid session');
        }
    }

    protected function verifyResultSecurity($result): void
    {
        // Verify no sensitive data exposure
        if ($this->containsSensitiveData($result)) {
            throw new SecurityException('Result contains sensitive data');
        }

        // Verify data integrity
        if (!$this->verifyDataIntegrity($result)) {
            throw new SecurityException('Result integrity check failed');
        }
    }

    protected function checkRateLimit(array $context): bool
    {
        $key = sprintf('rate_limit:%s:%s', 
            $context['user_id'] ?? 'anonymous',
            $context['operation'] ?? 'unknown'
        );

        return Cache::add(
            $key, 
            1, 
            $this->securityConfig['rate_limit_ttl'] ?? 60
        );
    }

    protected function validateIpAddress(?string $ip): void
    {
        if (!$ip || !in_array($ip, $this->securityConfig['allowed_ips'] ?? [])) {
            throw new SecurityException('IP not allowed');
        }
    }

    protected function validateSession($session): bool
    {
        return isset($session['id']) && 
               isset($session['expires_at']) &&
               $session['expires_at'] > time();
    }

    protected function containsSensitiveData($data): bool
    {
        // Check against patterns of sensitive data
        $patterns = $this->securityConfig['sensitive_patterns'] ?? [];
        return $this->validator->matchesPatterns($data, $patterns);
    }

    protected function verifyDataIntegrity($data): bool
    {
        return hash_equals(
            $data['hash'] ?? '',
            hash_hmac('sha256', json_encode($data), $this->securityConfig['app_key'])
        );
    }

    protected function getCriticalData(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'system_load' => sys_getloadavg(),
            'timestamp'   => microtime(true)
        ];
    }
}
