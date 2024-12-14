<?php

namespace App\Core\Validation;

class ValidationService
{
    private RuleRegistry $ruleRegistry;
    private ValidationCache $cache;
    private ErrorFormatter $errorFormatter;
    private ValidationLogger $logger;

    public function __construct(
        RuleRegistry $ruleRegistry,
        ValidationCache $cache,
        ErrorFormatter $errorFormatter,
        ValidationLogger $logger
    ) {
        $this->ruleRegistry = $ruleRegistry;
        $this->cache = $cache;
        $this->errorFormatter = $errorFormatter;
        $this->logger = $logger;
    }

    public function validate(array $data, array $rules, array $context = []): ValidationResult
    {
        $validationKey = $this->generateValidationKey($data, $rules);
        
        if ($cachedResult = $this->cache->get($validationKey)) {
            return $cachedResult;
        }

        $errors = [];
        foreach ($rules as $field => $fieldRules) {
            $value = data_get($data, $field);
            
            foreach ($fieldRules as $rule) {
                $validator = $this->ruleRegistry->getValidator($rule);
                
                if (!$validator->validate($value, $context)) {
                    $errors[$field][] = $validator->getMessage();
                }
            }
        }

        $result = new ValidationResult($errors);
        $this->cache->set($validationKey, $result);
        $this->logger->logValidation($data, $rules, $result);

        return $result;
    }

    public function registerRule(string $name, Validator $validator): void
    {
        $this->ruleRegistry->register($name, $validator);
    }

    public function clearCache(): void
    {
        $this->cache->clear();
    }

    protected function generateValidationKey(array $data, array $rules): string
    {
        return md5(serialize([
            'data' => $data,
            'rules' => $rules
        ]));
    }
}

class ValidationResult
{
    private array $errors;

    public function __construct(array $errors = [])
    {
        $this->errors = $errors;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(): ?string
    {
        return $this->hasErrors() ? reset($this->errors)[0] : null;
    }

    public function toArray(): array
    {
        return [
            'valid' => !$this->hasErrors(),
            'errors' => $this->errors
        ];
    }
}

interface Validator
{
    public function validate(mixed $value, array $context = []): bool;
    public function getMessage(): string;
}

class RuleRegistry
{
    private array $validators = [];

    public function register(string $name, Validator $validator): void
    {
        $this->validators[$name] = $validator;
    }

    public function getValidator(string $rule): Validator
    {
        [$name] = explode(':', $rule);
        
        if (!isset($this->validators[$name])) {
            throw new ValidatorNotFoundException($name);
        }

        return $this->validators[$name];
    }
}

class ValidationCache
{
    private CacheInterface $cache;
    private int $ttl;

    public function __construct(CacheInterface $cache, int $ttl = 3600)
    {
        $this->cache = $cache;
        $this->ttl = $ttl;
    }

    public function get(string $key): ?ValidationResult
    {
        return $this->cache->get($key);
    }

    public function set(string $key, ValidationResult $result): void
    {
        $this->cache->set($key, $result, $this->ttl);
    }

    public function clear(): void
    {
        $this->cache->clear();
    }
}

class ValidationLogger
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function logValidation(array $data, array $rules, ValidationResult $result): void
    {
        $this->logger->info('Validation performed', [
            'data' => $this->sanitizeData($data),
            'rules' => $rules,
            'result' => $result->toArray()
        ]);
    }

    protected function sanitizeData(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value) && strlen($value) > 100) {
                return substr($value, 0, 100) . '...';
            }
            return $value;
        }, $data);
    }
}
