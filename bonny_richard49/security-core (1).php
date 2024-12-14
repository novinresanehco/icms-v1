<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{ValidationService, EncryptionService, AuditService};
use App\Core\Exceptions\{SecurityException, ValidationException};

class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $audit;
    private array $securityConfig;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $audit,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->securityConfig = $securityConfig;
    }

    public function validateOperation(array $data, string $context): void 
    {
        DB::beginTransaction();
        try {
            // Input validation
            $this->validator->validate($data, $this->getValidationRules($context));

            // Security checks
            $this->performSecurityChecks($data, $context);

            // Audit logging
            $this->audit->logOperation($context, $data);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $context, $data);
            throw new SecurityException('Security validation failed', 0, $e);
        }
    }

    protected function performSecurityChecks(array $data, string $context): void 
    {
        // Check rate limiting
        $this->checkRateLimit($context);

        // Verify data integrity
        if (!$this->encryption->verifyIntegrity($data)) {
            throw new SecurityException('Data integrity check failed');
        }

        // Additional context-specific checks
        $this->performContextChecks($context, $data);
    }

    protected function checkRateLimit(string $context): void 
    {
        $limit = $this->securityConfig['rate_limits'][$context] ?? 0;
        if ($limit && !$this->isWithinRateLimit($context, $limit)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    protected function handleSecurityFailure(\Exception $e, string $context, array $data): void 
    {
        // Log failure with full context
        $this->audit->logFailure($context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'data' => $this->sanitizeData($data)
        ]);
    }

    protected function getValidationRules(string $context): array 
    {
        return $this->securityConfig['validation_rules'][$context] ?? [];
    }

    protected function performContextChecks(string $context, array $data): void 
    {
        $checks = $this->securityConfig['context_checks'][$context] ?? [];
        foreach ($checks as $check) {
            if (!$this->validateCheck($check, $data)) {
                throw new SecurityException("Context check failed: {$check}");
            }
        }
    }

    protected function isWithinRateLimit(string $context, int $limit): bool 
    {
        // Implement rate limiting logic here
        return true; 
    }

    protected function sanitizeData(array $data): array 
    {
        // Remove sensitive information before logging
        $sensitive = $this->securityConfig['sensitive_fields'] ?? [];
        return array_diff_key($data, array_flip($sensitive));
    }

    protected function validateCheck(string $check, array $data): bool 
    {
        // Implement specific security check validation
        return true;
    }
}
