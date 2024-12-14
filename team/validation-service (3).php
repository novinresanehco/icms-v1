<?php

namespace App\Core\Validation;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Events\ValidationEvent;
use App\Core\Exceptions\{ValidationException, SecurityException};
use Illuminate\Support\Facades\{DB, Log};

class ValidationService implements ValidationInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private array $rules = [];
    private array $sanitizers = [];
    private array $validators = [];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->registerDefaultValidators();
        $this->registerDefaultSanitizers();
    }

    public function validate(array $data, array $rules): array
    {
        return $this->security->executeCriticalOperation(
            function() use ($data, $rules) {
                // Pre-validation sanitization
                $sanitizedData = $this->sanitizeData($data);

                // Security pre-check
                $this->performSecurityCheck($sanitizedData);

                // Validate data
                $errors = $this->validateData($sanitizedData, $rules);

                if (!empty($errors)) {
                    throw new ValidationException(
                        'Validation failed',
                        ['errors' => $errors]
                    );
                }

                // Post-validation security check
                $this->performSecurityPostCheck($sanitizedData);

                event(new ValidationEvent('validation_success', [
                    'rules' => $rules,
                    'data_hash' => hash('sha256', serialize($sanitizedData))
                ]));

                return $sanitizedData;
            },
            ['operation' => 'validate_data']
        );
    }

    public function addRule(string $name, callable $validator, callable $sanitizer = null): void
    {
        $this->validators[$name] = $validator;
        if ($sanitizer) {
            $this->sanitizers[$name] = $sanitizer;
        }
    }

    public function addSanitizer(string $name, callable $sanitizer): void
    {
        $this->sanitizers[$name] = $sanitizer;
    }

    protected function sanitizeData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $sanitized[$key] = $this->sanitizeValue($value);
        }
        return $sanitized;
    }

    protected function sanitizeValue($value)
    {
        if (is_array($value)) {
            return $this->sanitizeData($value);
        }

        if (is_string($value)) {
            $value = $this->applySanitizers($value);
        }

        return $value;
    }

    protected function applySanitizers(string $value): string
    {
        foreach ($this->sanitizers as $sanitizer) {
            $value = $sanitizer($value);
        }
        return $value;
    }

    protected function validateData(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $fieldRules) {
            $fieldErrors = $this->validateField(
                $data[$field] ?? null,
                $fieldRules,
                $field
            );
            if (!empty($fieldErrors)) {
                $errors[$field] = $fieldErrors;
            }
        }
        return $errors;
    }

    protected function validateField($value, $rules, string $field): array
    {
        $errors = [];
        $ruleSet = is_string($rules) ? explode('|', $rules) : $rules;

        foreach ($ruleSet as $rule) {
            $ruleName = is_string($rule) ? explode(':', $rule)[0] : $rule;
            $parameters = is_string($rule) ? 
                         array_slice(explode(':', $rule), 1) : [];

            if (!$this->checkRule($ruleName, $value, $parameters, $field)) {
                $errors[] = $this->getErrorMessage($ruleName, $field, $parameters);
            }
        }

        return $errors;
    }

    protected function checkRule(string $rule, $value, array $parameters, string $field): bool
    {
        if (!isset($this->validators[$rule])) {
            throw new ValidationException("Unknown validation rule: {$rule}");
        }

        try {
            return $this->validators[$rule]($value, $parameters, $field);
        } catch (\Exception $e) {
            Log::error('Validation rule check failed', [
                'rule' => $rule,
                'field' => $field,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function performSecurityCheck(array $data): void
    {
        foreach ($data as $key => $value) {
            if ($this->isSecurityThreat($value)) {
                throw new SecurityException(
                    'Security check failed: Potentially malicious input detected'
                );
            }
        }
    }

    protected function performSecurityPostCheck(array $data): void
    {
        // Additional security checks after validation
        foreach ($data as $key => $value) {
            if ($this->containsSuspiciousPatterns($value)) {
                throw new SecurityException(
                    'Post-validation security check failed'
                );
            }
        }
    }

    protected function isSecurityThreat($value): bool
    {
        if (is_string($value)) {
            // Check for common attack patterns
            $patterns = [
                '/(<|%3C)script[\s\S]*?(>|%3E)/i',
                '/javascript:[^\s]*/i',
                '/data:[^\s]*/i',
                '/vbscript:[^\s]*/i',
                '/onload=[^\s]*/i',
                '/onerror=[^\s]*/i'
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function containsSuspiciousPatterns($value): bool
    {
        if (is_string($value)) {
            // Check for SQL injection patterns
            $sqlPatterns = [
                '/UNION\s+SELECT/i',
                '/UNION\s+ALL\s+SELECT/i',
                '/INTO\s+OUTFILE/i',
                '/LOAD_FILE/i'
            ];

            foreach ($sqlPatterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function getErrorMessage(string $rule, string $field, array $parameters): string
    {
        $messages = [
            'required' => 'The :field field is required.',
            'email' => 'The :field must be a valid email address.',
            'min' => 'The :field must be at least :min characters.',
            'max' => 'The :field may not be greater than :max characters.',
            'numeric' => 'The :field must be a number.',
            'alpha' => 'The :field may only contain letters.',
            'alpha_num' => 'The :field may only contain letters and numbers.'
        ];

        $message = $messages[$rule] ?? 'The :field field is invalid.';
        $message = str_replace(':field', $field, $message);

        foreach ($parameters as $key => $value) {
            $message = str_replace(":$key", $value, $message);
        }

        return $message;
    }

    protected function registerDefaultValidators(): void
    {
        $this->addRule('required', function($value) {
            return !is_null($value) && $value !== '';
        });

        $this->addRule('email', function($value) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        });

        $this->addRule('numeric', function($value) {
            return is_numeric($value);
        });

        $this->addRule('min', function($value, $parameters) {
            return strlen($value) >= $parameters[0];
        });

        $this->addRule('max', function($value, $parameters) {
            return strlen($value) <= $parameters[0];
        });
    }

    protected function registerDefaultSanitizers(): void
    {
        $this->addSanitizer('trim', function($value) {
            return trim($value);
        });

        $this->addSanitizer('strip_tags', function($value) {
            return strip_tags($value);
        });

        $this->addSanitizer('escape', function($value) {
            return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        });
    }
}
