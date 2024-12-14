<?php

namespace App\Core\Validation\Services;

class ValidationManager
{
    private RuleRegistry $ruleRegistry;
    private ValidatorFactory $validatorFactory;
    private ErrorFormatter $errorFormatter;

    public function validate(array $data, array $rules, array $messages = []): ValidationResult
    {
        $validator = $this->validatorFactory->make($data, $rules, $messages);
        
        if ($validator->fails()) {
            $errors = $this->errorFormatter->format($validator->errors());
            throw new ValidationException($errors);
        }

        return new ValidationResult($data, true);
    }

    public function registerRule(string $name, callable $rule): void
    {
        $this->ruleRegistry->register($name, $rule);
    }
}

class RuleRegistry
{
    private array $rules = [];

    public function register(string $name, callable $rule): void
    {
        $this->rules[$name] = $rule;
    }

    public function getRule(string $name): callable
    {
        if (!isset($this->rules[$name])) {
            throw new RuleNotFoundException("Rule {$name} not found");
        }
        return $this->rules[$name];
    }
}

class ValidatorFactory
{
    private RuleRegistry $ruleRegistry;

    public function make(array $data, array $rules, array $messages = []): Validator
    {
        return new Validator($data, $rules, $messages, $this->ruleRegistry);
    }
}

class Validator
{
    private array $data;
    private array $rules;
    private array $messages;
    private RuleRegistry $ruleRegistry;
    private array $errors = [];

    public function validate(): bool
    {
        foreach ($this->rules as $field => $rules) {
            $this->validateField($field, $rules);
        }

        return empty($this->errors);
    }

    private function validateField(string $field, array $rules): void
    {
        $value = $this->data[$field] ?? null;

        foreach ($rules as $rule) {
            $validator = $this->ruleRegistry->getRule($rule);
            
            if (!$validator($value)) {
                $this->errors[$field][] = $this->formatError($field, $rule);
            }
        }
    }

    public function fails(): bool
    {
        return !$this->validate();
    }

    public function errors(): array
    {
        return $this->errors;
    }
}

class ValidationResult
{
    private array $data;
    private bool $valid;

    public function __construct(array $data, bool $valid)
    {
        $this->data = $data;
        $this->valid = $valid;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getData(): array
    {
        return $this->data;
    }
}

class ErrorFormatter
{
    public function format(array $errors): array
    {
        $formatted = [];
        
        foreach ($errors as $field => $messages) {
            $formatted[$field] = implode(', ', $messages);
        }
        
        return $formatted;
    }
}

namespace App\Core\Validation\Rules;

class RequiredRule
{
    public function __invoke($value): bool
    {
        if (is_null($value)) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_array($value) && empty($value)) {
            return false;
        }

        return true;
    }
}

class EmailRule
{
    public function __invoke($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}

class MinLengthRule
{
    private int $length;

    public function __construct(int $length)
    {
        $this->length = $length;
    }

    public function __invoke($value): bool
    {
        return strlen($value) >= $this->length;
    }
}

class MaxLengthRule
{
    private int $length;

    public function __construct(int $length)
    {
        $this->length = $length;
    }

    public function __invoke($value): bool
    {
        return strlen($value) <= $this->length;
    }
}

namespace App\Core\Validation\Http\Controllers;

class ValidationController extends Controller
{
    private ValidationManager $validationManager;

    public function validate(Request $request): JsonResponse
    {
        try {
            $result = $this->validationManager->validate(
                $request->all(),
                $request->input('rules', []),
                $request->input('messages', [])
            );

            return response()->json([
                'valid' => true,
                'data' => $result->getData()
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'valid' => false,
                'errors' => $e->errors()
            ], 422);
        }
    }
}
