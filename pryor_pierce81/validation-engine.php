```php
namespace App\Core\Validation;

class CriticalValidationEngine {
    private RuleEngine $rules;
    private SecurityValidator $security;
    private AuditLogger $logger;

    public function validateInput(string $operation, array $params): void {
        // Get operation rules
        $rules = $this->rules->getOperationRules($operation);
        
        // Validate each parameter
        foreach ($rules as $param => $rule) {
            if (!$this->validateParam($params[$param] ?? null, $rule)) {
                throw new ValidationException("Invalid parameter: $param");
            }
        }
        
        // Additional security validation
        $this->security->validateInputData($operation, $params);
        
        // Log validation success
        $this->logger->logValidation($operation, 'input', $params);
    }

    public function validateOutput($result): void {
        if (!$this->isValidResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
        
        if ($result instanceof DataContainer) {
            $this->validateDataContainer($result);
        }
    }

    private function validateParam($value, Rule $rule): bool {
        try {
            return $rule->validate($value);
        } catch (\Exception $e) {
            $this->logger->logValidationFailure('parameter', $e);
            return false;
        }
    }

    private function validateDataContainer(DataContainer $container): void {
        foreach ($container->getData() as $key => $value) {
            if (!$this->isValidData($key, $value)) {
                throw new ValidationException("Invalid data for key: $key");
            }
        }
    }

    private function isValidResult($result): bool {
        return 
            $result === null ||
            is_scalar($result) ||
            $result instanceof DataContainer ||
            (is_array($result) && $this->isValidArray($result));
    }

    private function isValidArray(array $data): bool {
        foreach ($data as $value) {
            if (!$this->isValidResult($value)) {
                return false;
            }
        }
        return true;
    }
}

interface RuleEngine {
    public function getOperationRules(string $operation): array;
}

interface Rule {
    public function validate($value): bool;
    public function getMessage(): string;
}

class ValidationException extends \Exception {}

class DataContainer {
    private array $data;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function getData(): array {
        return $this->data;
    }
}
```
