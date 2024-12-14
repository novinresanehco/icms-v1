namespace App\Core\Security;

class ValidationManager implements ValidationManagerInterface
{
    private SecurityConfig $config;
    private AuditLogger $audit;
    private MetricsCollector $metrics;

    public function validateOperation(CriticalOperation $operation, SecurityContext $context): ValidationResult 
    {
        $startTime = microtime(true);

        try {
            // Pre-validation checks
            $this->validateContext($context);
            $this->validatePermissions($context, $operation);
            $this->validateRateLimits($context, $operation);
            $this->validateInputData($operation->getData(), $operation->getRules());

            // Record success metrics
            $this->metrics->recordValidation(
                $operation->getType(),
                microtime(true) - $startTime,
                true
            );

            return new ValidationResult(true);

        } catch (ValidationException $e) {
            // Log failure and record metrics
            $this->audit->logValidationFailure($operation, $context, $e);
            $this->metrics->recordValidation(
                $operation->getType(),
                microtime(true) - $startTime,
                false
            );
            throw $e;
        }
    }

    private function validateContext(SecurityContext $context): void 
    {
        if (!$context->isValid()) {
            throw new ValidationException('Invalid security context');
        }

        if ($this->isContextExpired($context)) {
            throw new ValidationException('Security context expired');
        }

        if (!$this->verifyContextIntegrity($context)) {
            throw new SecurityException('Context integrity check failed');
        }
    }

    private function validatePermissions(SecurityContext $context, CriticalOperation $operation): void 
    {
        $required = $operation->getRequiredPermissions();
        $actual = $context->getPermissions();

        foreach ($required as $permission) {
            if (!in_array($permission, $actual)) {
                $this->audit->logUnauthorizedAccess($context, $operation);
                throw new UnauthorizedException("Missing required permission: $permission");
            }
        }
    }

    private function validateRateLimits(SecurityContext $context, CriticalOperation $operation): void 
    {
        $key = $this->getRateLimitKey($context, $operation);
        $limit = $this->config->getRateLimit($operation->getType());
        
        if (!$this->checkRateLimit($key, $limit)) {
            $this->audit->logRateLimitExceeded($context, $operation);
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function validateInputData(array $data, array $rules): void 
    {
        foreach ($rules as $field => $rule) {
            if (!$this->validateField($data[$field] ?? null, $rule)) {
                throw new ValidationException("Validation failed for field: $field");
            }
        }
    }

    private function checkRateLimit(string $key, RateLimit $limit): bool 
    {
        $currentCount = Cache::get($key, 0);
        
        if ($currentCount >= $limit->max) {
            return false;
        }

        Cache::put($key, $currentCount + 1, $limit->period);
        return true;
    }

    private function verifyContextIntegrity(SecurityContext $context): bool 
    {
        $signature = $context->getSignature();
        $data = $context->getData();
        
        return hash_equals(
            $signature,
            hash_hmac('sha256', json_encode($data), $this->config->getSecretKey())
        );
    }

    private function isContextExpired(SecurityContext $context): bool 
    {
        $expiration = $context->getExpiration();
        return $expiration && $expiration < time();
    }
}

class ValidationResult
{
    private bool $success;
    private array $errors;

    public function __construct(bool $success, array $errors = [])
    {
        $this->success = $success;
        $this->errors = $errors;
    }

    public function isValid(): bool
    {
        return $this->success;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class SecurityContext implements SecurityContextInterface
{
    private array $data;
    private string $signature;
    private ?int $expiration;
    private array $permissions;

    public function __construct(array $data, string $signature, ?int $expiration, array $permissions)
    {
        $this->data = $data;
        $this->signature = $signature;
        $this->expiration = $expiration;
        $this->permissions = $permissions;
    }

    public function isValid(): bool
    {
        return !empty($this->data) && !empty($this->signature);
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function getExpiration(): ?int
    {
        return $this->expiration;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }
}

class ValidationRule
{
    private string $type;
    private array $parameters;

    public function __construct(string $type, array $parameters = [])
    {
        $this->type = $type;
        $this->parameters = $parameters;
    }

    public function validate($value): bool
    {
        switch ($this->type) {
            case 'required':
                return !empty($value);
            case 'string':
                return is_string($value);
            case 'numeric':
                return is_numeric($value);
            case 'array':
                return is_array($value);
            case 'min':
                return strlen($value) >= $this->parameters['length'];
            case 'max':
                return strlen($value) <= $this->parameters['length'];
            case 'pattern':
                return preg_match($this->parameters['pattern'], $value);
            case 'enum':
                return in_array($value, $this->parameters['values']);
            default:
                throw new ValidationException("Unknown validation rule: {$this->type}");
        }
    }
}
