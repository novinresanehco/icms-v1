```php
namespace App\Core\Database\Validation;

class QueryValidationEngine {
    private RuleEngine $rules;
    private SecurityValidator $security;
    private AuditLogger $logger;

    public function validateQuery(string $operation, array $params): void {
        // Get operation rules
        $rules = $this->rules->getQueryRules($operation);
        
        // Validate each parameter
        foreach ($rules as $param => $rule) {
            if (!$this->validateParam($params[$param] ?? null, $rule)) {
                throw new ValidationException("Invalid query parameter: $param");
            }
        }
        
        // Security validation
        $this->security->validateQuery($operation, $params);
        
        // Log validation success
        $this->logger->logValidation('query', $operation, $params);
    }

    public function validateResult($result): void {
        if (!$this->isValidResult($result)) {
            throw new ValidationException('Invalid query result structure');
        }
        
        if (is_array($result)) {
            $this->validateResultSet($result);
        }
    }

    private function validateParam($value, QueryRule $rule): bool {
        try {
            return $rule->validate($value);
        } catch (\Exception $e) {
            $this->logger->logValidationFailure('query_parameter', $e);
            return false;
        }
    }

    private function validateResultSet(array $result): void {
        foreach ($result as $row) {
            if (!$this->isValidRow($row)) {
                throw new ValidationException('Invalid result row structure');
            }
        }
    }
}

interface QueryRule {
    public function validate($value): bool;
    public function getMessage(): string;
}

interface RuleEngine {
    public function getQueryRules(string $operation): array;
}

class ValidationException extends \Exception {}
```
