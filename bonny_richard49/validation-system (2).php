// app/Core/Validation/Validator.php
<?php

namespace App\Core\Validation;

use Illuminate\Support\Facades\Validator as LaravelValidator;
use App\Core\Validation\Rules\RuleFactory;
use App\Core\Validation\Exceptions\ValidationException;

class Validator
{
    private RuleFactory $ruleFactory;
    private array $customRules = [];
    private array $messages = [];

    public function __construct(RuleFactory $ruleFactory)
    {
        $this->ruleFactory = $ruleFactory;
    }

    public function validate(array $data, array $rules, array $messages = []): array
    {
        $validator = LaravelValidator::make(
            $data,
            $this->prepareRules($rules),
            array_merge($this->messages, $messages)
        );

        foreach ($this->customRules as $name => $rule) {
            $validator->addExtension($name, $rule);
        }

        if ($validator->fails()) {
            throw new ValidationException(
                "Validation failed",
                $validator->errors()->toArray()
            );
        }

        return $validator->validated();
    }

    public function addRule(string $name, callable $rule): void
    {
        $this->customRules[$name] = $rule;
    }

    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    private function prepareRules(array $rules): array
    {
        $prepared = [];
        
        foreach ($rules as $field => $fieldRules) {
            $prepared[$field] = $this->parseRules($fieldRules);
        }
        
        return $prepared;
    }

    private function parseRules($rules): array
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }

        return array_map(function ($rule) {
            if (is_string($rule)) {
                return $this->parseRule($rule);
            }
            return $rule;
        }, $rules);
    }

    private function parseRule(string $rule): string|object
    {
        if (str_contains($rule, ':')) {
            [$name, $parameters] = explode(':', $rule, 2);
            return $this->ruleFactory->create($name, explode(',', $parameters));
        }

        return $this->ruleFactory->create($rule);
    }
}

// app/Core/Validation/Rules/RuleFactory.php
<?php

namespace App\Core\Validation\Rules;

use App\Core\Validation\Rules\CustomRule;
use InvalidArgumentException;

class RuleFactory
{
    private array $rules = [];

    public function register(string $name, string $ruleClass): void
    {
        $this->rules[$name] = $ruleClass;
    }

    public function create(string $name, array $parameters = []): string|object
    {
        if (!isset($this->rules[$name])) {
            return $name;
        }

        $ruleClass = $this->rules[$name];

        if (!class_exists($ruleClass)) {
            throw new InvalidArgumentException("Rule class {$ruleClass} not found");
        }

        return new $ruleClass(...$parameters);
    }
}

// app/Core/Validation/Rules/CustomRule.php
<?php

namespace App\Core\Validation\Rules;

use Illuminate\Contracts\Validation\Rule;

abstract class CustomRule implements Rule
{
    protected array $parameters = [];
    protected string $message = '';

    public function __construct(...$parameters)
    {
        $this->parameters = $parameters;
    }

    public function message(): string
    {
        return $this->message;
    }

    abstract public function passes($attribute, $value): bool;
}

// app/Core/Validation/Rules/PhoneRule.php
<?php

namespace App\Core\Validation\Rules;

use App\Core\Validation\Rules\CustomRule;

class PhoneRule extends CustomRule
{
    protected string $message = 'The :attribute field must be a valid phone number';

    public function passes($attribute, $value): bool
    {
        return preg_match('/^\+?[1-9]\d{1,14}$/', $value);
    }
}

// app/Core/Validation/Rules/PasswordRule.php
<?php

namespace App\Core\Validation\Rules;

use App\Core\Validation\Rules\CustomRule;

class PasswordRule extends CustomRule
{
    protected string $message = 'The password must be at least 8 characters and contain at least one uppercase letter, one lowercase letter, one number, and one special character';

    public function passes($attribute, $value): bool
    {
        return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $value);
    }
}

// app/Core/Validation/Rules/JsonRule.php
<?php

namespace App\Core\Validation\Rules;

use App\Core\Validation\Rules\CustomRule;

class JsonRule extends CustomRule
{
    protected string $message = 'The :attribute field must be a valid JSON string';

    public function passes($attribute, $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

// app/Core/Validation/Rules/DateTimeRule.php
<?php

namespace App\Core\Validation\Rules;

use App\Core\Validation\Rules\CustomRule;

class DateTimeRule extends CustomRule
{
    protected string $message = 'The :attribute field must be a valid datetime in the format Y-m-d H:i:s';

    public function passes($attribute, $value): bool
    {
        $format = $this->parameters[0] ?? 'Y-m-d H:i:s';
        $date = \DateTime::createFromFormat($format, $value);
        return $date && $date->format($format) === $value;
    }
}

// app/Core/Validation/Exceptions/ValidationException.php
<?php

namespace App\Core\Validation\Exceptions;

use Exception;

class ValidationException extends Exception
{
    private array $errors;

    public function __construct(string $message, array $errors = [], int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
