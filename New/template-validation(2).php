<?php

namespace App\Core\Template\Validation;

use App\Core\Template\Exceptions\ValidationException;

class ValidationRule
{
    private string $name;
    private callable $validator;
    private string $errorMessage;

    public function __construct(string $name, callable $validator, string $errorMessage)
    {
        $this->name = $name;
        $this->validator = $validator;
        $this->errorMessage = $errorMessage;
    }

    public function validate(string $content): bool
    {
        return ($this->validator)($content);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }
}

class TemplateValidationManager
{
    private array $rules = [];
    private array $errors = [];

    public function addRule(ValidationRule $rule): void
    {
        $this->rules[$rule->getName()] = $rule;
    }

    public function validate(string $content): bool
    {
        $this->errors = [];
        
        foreach ($this->rules as $rule) {
            if (!$rule->validate($content)) {
                $this->errors[] = $rule->getErrorMessage();
            }
        }
        
        if (!empty($this->errors)) {
            throw new ValidationException($this->errors);
        }
        
        return true;
    }

    public function getDefaultRules(): array
    {
        return [
            new ValidationRule(
                'syntax',
                fn($content) => $this->validateSyntax($content),
                'Template contains invalid PHP syntax'
            ),
            new ValidationRule(
                'resourceUsage',
                fn($content) => $this->validateResourceUsage($content),
                'Template exceeds resource usage limits'
            ),
            new ValidationRule(
                'security',
                fn($content) => $this->validateSecurity($content),
                'Template contains potentially unsafe code'
            )
        ];
    }

    private function validateSyntax(string $content): bool
    {
        return @token_get_all($content, TOKEN_PARSE) !== false;
    }

    private function validateResourceUsage(string $content): bool
    {
        $metrics = [
            'loops' => preg_match_all('/@(foreach|for|while)/', $content),
            'conditionals' => preg_match_all('/@(if|switch)/', $content),
            'expressions' => preg_match_all('/\{\{.*?\}\}/', $content)
        ];

        return $metrics['loops'] <= 10 && 
               $metrics['conditionals'] <= 20 && 
               $metrics['expressions'] <= 50;
    }

    private function validateSecurity(string $content): bool
    {
        $patterns = [
            '/\b(eval|exec|system|shell_exec|passthru)\b/',
            '/\$_(GET|POST|REQUEST|COOKIE|SERVER|ENV|FILES)/',
            '/`.*?`/',
            '/<\?(?!php|=)/',
            '/\$\{.*?\}/',
            '/\(\s*\$\w+\s*\(\s*\)/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }

        return true;
    }
}

class TemplateMonitoringService
{
    private array $metrics = [];
    private \PDO $db;
    private string $environment;

    public function __construct(\PDO $db, string $environment = 'production')
    {
        $this->db = $db;
        $this->environment = $environment;
    }

    public function recordMetric(string $name, float $value, array $tags = []): void
    {
        $metric = [
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => microtime(true)
        ];

        $this->metrics[] = $metric;
        
        if (count($this->metrics) >= 100) {
            $this->flush();
        }
    }

    public function recordError(string $message, string $level = 'error', array $context = []): void
    {
        $data = [
            'message' => $message,
            'level' => $level,
            'context' => json_encode($context),
            'environment' => $this->environment,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $sql = "INSERT INTO template_errors (message, level, context, environment, created_at) 
                VALUES (:message, :level, :context, :environment, :timestamp)";
                
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);
    }

    private function flush(): void
    {
        if (empty($this->metrics)) {
            return;
        }

        $sql = "INSERT INTO template_metrics (name, value, tags, timestamp, environment) 
                VALUES (:name, :value, :tags, :timestamp, :environment)";
                
        $stmt = $this->db->prepare($sql);

        foreach ($this->metrics as $metric) {
            $data = [
                'name' => $metric['name'],
                'value' => $metric['value'],
                'tags' => json_encode($metric['tags']),
                'timestamp' => date('Y-m-d H:i:s', (int)$metric['timestamp']),
                'environment' => $this->environment
            ];
            
            $stmt->execute($data);
        }

        $this->metrics = [];
    }

    public function __destruct()
    {
        $this->flush();
    }
}
