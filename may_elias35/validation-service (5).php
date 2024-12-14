namespace App\Core\Services;

use App\Core\Contracts\ValidationInterface;
use App\Core\Exceptions\ValidationException;
use Illuminate\Support\Facades\Log;

class ValidationService implements ValidationInterface
{
    private array $config;
    private array $rules;
    private MetricsCollector $metrics;

    public function __construct(array $config, MetricsCollector $metrics)
    {
        $this->config = $config;
        $this->metrics = $metrics;
        $this->initializeRules();
    }

    public function validate(array $context): bool
    {
        try {
            $this->validateInput($context);
            $this->validateBusinessRules($context);
            $this->validateSecurityConstraints($context);
            
            $this->metrics->recordValidation('success');
            return true;
            
        } catch (ValidationException $e) {
            $this->metrics->recordValidation('failure', $e->getMessage());
            Log::error('Validation failed', [
                'context' => $context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function validateInput(array $data): bool
    {
        foreach ($this->rules as $field => $rules) {
            if (!$this->validateField($data[$field] ?? null, $rules)) {
                throw new ValidationException("Field validation failed: {$field}");
            }
        }
        return true;
    }

    public function validateResult($result): bool
    {
        if (empty($result)) {
            throw new ValidationException('Empty result not allowed');
        }

        if (!$this->validateDataIntegrity($result)) {
            throw new ValidationException('Result integrity check failed');
        }

        if (!$this->validateBusinessConstraints($result)) {
            throw new ValidationException('Business constraint validation failed');
        }

        return true;
    }

    public function checkPermissions(array $context): bool
    {
        $user = $context['user'] ?? null;
        $required = $context['required_permissions'] ?? [];

        if (!$user || empty($required)) {
            throw new ValidationException('Invalid permission context');
        }

        foreach ($required as $permission) {
            if (!$this->hasPermission($user, $permission)) {
                return false;
            }
        }

        return true;
    }

    public function verifyIntegrity(array $data): bool
    {
        if (!isset($data['hash'])) {
            throw new ValidationException('Missing integrity hash');
        }

        return hash_equals(
            $data['hash'],
            $this->calculateHash($data['content'])
        );
    }

    protected function validateField($value, array $rules): bool
    {
        foreach ($rules as $rule => $param) {
            if (!$this->applyRule($value, $rule, $param)) {
                return false;
            }
        }
        return true;
    }

    protected function validateDataIntegrity($data): bool
    {
        return !empty($data) && 
               $this->verifyDataStructure($data) && 
               $this->checkDataConstraints($data);
    }

    protected function validateBusinessConstraints($data): bool
    {
        foreach ($this->config['business_rules'] as $rule) {
            if (!$this->checkBusinessRule($data, $rule)) {
                return false;
            }
        }
        return true;
    }

    protected function validateSecurityConstraints(array $context): bool
    {
        return $this->validateAccessControl($context) && 
               $this->validateRateLimits($context) &&
               $this->validateSecurityTokens($context);
    }

    private function initializeRules(): void
    {
        $this->rules = $this->config['validation_rules'] ?? [];
    }

    private function calculateHash(mixed $content): string
    {
        return hash_hmac('sha256', serialize($content), $this->config['hash_key']);
    }

    private function hasPermission($user, string $permission): bool
    {
        return isset($user['permissions']) && 
               in_array($permission, $user['permissions']);
    }
}
