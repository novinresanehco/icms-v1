<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\ValidationException;
use App\Core\Interfaces\ValidationInterface;

class ValidationService implements ValidationInterface
{
    private array $rules;
    private array $customValidators;
    private int $cacheExpiry;
    
    public function __construct(array $rules, int $cacheExpiry = 3600)
    {
        $this->rules = $rules;
        $this->customValidators = [];
        $this->cacheExpiry = $cacheExpiry;
    }

    public function validate(array $data, array $rules = []): array
    {
        $rules = $rules ?: $this->rules;
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            if (!isset($data[$field]) && $this->isRequired($fieldRules)) {
                $errors[$field][] = "Field is required";
                continue;
            }

            if (isset($data[$field])) {
                $value = $data[$field];
                $cacheKey = $this->generateValidationCacheKey($field, $value, $fieldRules);

                $validationResult = Cache::remember(
                    $cacheKey,
                    $this->cacheExpiry,
                    fn() => $this->validateField($value, $fieldRules)
                );

                if (!$validationResult['valid']) {
                    $errors[$field] = $validationResult['errors'];
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException(
                'Validation failed',
                ['validation_errors' => $errors]
            );
        }

        return $data;
    }

    public function validateField($value, array|string $rules): array
    {
        $rules = is_string($rules) ? explode('|', $rules) : $rules;
        $errors = [];

        foreach ($rules as $rule) {
            $ruleName = is_string($rule) ? $rule : $rule['rule'];
            $params = is_array($rule) ? ($rule['params'] ?? []) : [];

            if (!$this->checkRule($value, $ruleName, $params)) {
                $errors[] = $this->getErrorMessage($ruleName, $params);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    public function addValidator(string $name, callable $validator, string $errorMessage): void
    {
        $this->customValidators[$name] = [
            'validator' => $validator,
            'message' => $errorMessage
        ];
    }

    protected function checkRule($value, string $rule, array $params = []): bool
    {
        if (isset($this->customValidators[$rule])) {
            return ($this->customValidators[$rule]['validator'])($value, $params);
        }

        return match ($rule) {
            'required' => !empty($value),
            'numeric' => is_numeric($value),
            'string' => is_string($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'min' => is_numeric($value) ? $value >= ($params[0] ?? 0) : strlen($value) >= ($params[0] ?? 0),
            'max' => is_numeric($value) ? $value <= ($params[0] ?? 0) : strlen($value) <= ($params[0] ?? 0),
            'array' => is_array($value),
            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1', true, false], true),
            'date' => strtotime($value) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false,
            'json' => $this->isValidJson($value),
            'regex' => isset($params[0]) && preg_match($params[0], $value),
            default => false
        };
    }

    protected function isRequired(array|string $rules): bool
    {
        $rules = is_string($rules) ? explode('|', $rules) : $rules;
        return in_array('required', $rules);
    }

    protected function getErrorMessage(string $rule, array $params = []): string
    {
        if (isset($this->customValidators[$rule])) {
            return $this->customValidators[$rule]['message'];
        }

        return match ($rule) {
            'required' => 'Field is required',
            'numeric' => 'Must be a number',
            'string' => 'Must be a string',
            'email' => 'Must be a valid email',
            'min' => "Must be at least {$params[0]}",
            'max' => "Must not exceed {$params[0]}",
            'array' => 'Must be an array',
            'boolean' => 'Must be a boolean',
            'date' => 'Must be a valid date',
            'url' => 'Must be a valid URL',
            'ip' => 'Must be a valid IP address',
            'json' => 'Must be valid JSON',
            'regex' => 'Invalid format',
            default => 'Validation failed'
        };
    }

    protected function generateValidationCacheKey(string $field, $value, array|string $rules): string
    {
        $valueHash = is_scalar($value) ? $value : md5(json_encode($value));
        $rulesHash = is_array($rules) ? md5(json_encode($rules)) : md5($rules);
        return "validation:{$field}:{$valueHash}:{$rulesHash}";
    }

    protected function isValidJson($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        
        try {
            json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            return true;
        } catch (\JsonException $e) {
            return false;
        }
    }
}
