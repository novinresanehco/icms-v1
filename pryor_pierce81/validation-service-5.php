namespace App\Core\Validation;

class ValidationService implements ValidationInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private array $customRules;
    private array $securityPatterns;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->loadValidationRules();
        $this->loadSecurityPatterns();
    }

    public function validate(array $data, array $rules): array
    {
        $startTime = microtime(true);

        try {
            $this->validateStructure($data, $rules);
            $this->validateSecurity($data);
            $validated = $this->validateRules($data, $rules);

            $this->metrics->timing(
                'validation.duration',
                microtime(true) - $startTime
            );

            return $validated;
        } catch (\Exception $e) {
            $this->metrics->increment('validation.failures');
            throw new ValidationException($e->getMessage());
        }
    }

    private function validateStructure(array $data, array $rules): void
    {
        foreach ($rules as $field => $rule) {
            if (str_contains($rule, 'required') && !isset($data[$field])) {
                throw new ValidationException("Field {$field} is required");
            }

            if (isset($data[$field])) {
                $this->validateFieldType($field, $data[$field], $rule);
            }
        }
    }

    private function validateSecurity(array $data): void
    {
        foreach ($data as $field => $value) {
            $this->validatePattern($field, $value);
            $this->validateXSS($value);
            $this->validateSQLInjection($value);
            $this->validateSpecialChars($value);
        }
    }

    private function validateRules(array $data, array $rules): array
    {
        $validated = [];

        foreach ($rules as $field => $rule) {
            if (isset($data[$field])) {
                $validated[$field] = $this->validateField(
                    $field,
                    $data[$field],
                    $this->parseRules($rule)
                );
            }
        }

        return $validated;
    }

    private function validateField(string $field, $value, array $rules): mixed
    {
        foreach ($rules as $rule => $parameters) {
            if (isset($this->customRules[$rule])) {
                $value = $this->customRules[$rule]($value, $parameters);
            } else {
                $value = $this->applyBuiltInRule($rule, $value, $parameters);
            }
        }

        return $value;
    }

    private function validatePattern(string $field, $value): void
    {
        if (isset($this->securityPatterns[$field])) {
            $pattern = $this->securityPatterns[$field];
            if (!preg_match($pattern, (string)$value)) {
                throw new ValidationException("Invalid format for field {$field}");
            }
        }
    }

    private function validateXSS($value): void
    {
        if (is_string($value)) {
            $cleaned = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            if ($cleaned !== $value) {
                throw new ValidationException('Potential XSS detected');
            }
        }
    }

    private function validateSQLInjection($value): void
    {
        if (is_string($value)) {
            $patterns = [
                '/\bSELECT\b/i',
                '/\bINSERT\b/i',
                '/\bUPDATE\b/i',
                '/\bDELETE\b/i',
                '/\bDROP\b/i',
                '/\bUNION\b/i'
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    throw new ValidationException('Potential SQL injection detected');
                }
            }
        }
    }

    private function validateSpecialChars($value): void
    {
        if (is_string($value)) {
            $dangerous = ['<script>', '<?php', '<%', '<asp'];
            foreach ($dangerous as $char) {
                if (stripos($value, $char) !== false) {
                    throw new ValidationException('Invalid characters detected');
                }
            }
        }
    }

    private function parseRules(string $rules): array
    {
        return $this->cache->remember(
            'validation_rules:' . md5($rules),
            3600,
            fn() => $this->parseRuleString($rules)
        );
    }

    private function loadValidationRules(): void
    {
        $this->customRules = require config_path('validation.php');
    }

    private function loadSecurityPatterns(): void
    {
        $this->securityPatterns = require config_path('security_patterns.php');
    }

    private function validateFieldType(string $field, $value, string $rule): void
    {
        $type = $this->getFieldType($rule);
        if ($type && !$this->checkType($value, $type)) {
            throw new ValidationException("Invalid type for field {$field}");
        }
    }

    private function getFieldType(string $rule): ?string
    {
        $types = ['string', 'integer', 'float', 'boolean', 'array', 'date'];
        foreach ($types as $type) {
            if (str_contains($rule, $type)) {
                return $type;
            }
        }
        return null;
    }

    private function checkType($value, string $type): bool
    {
        return match($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'float' => is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'date' => $value instanceof \DateTime,
            default => true
        };
    }
}
