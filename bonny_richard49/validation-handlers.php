// app/Core/Validation/Handlers/AbstractValidationHandler.php
<?php

namespace App\Core\Validation\Handlers;

abstract class AbstractValidationHandler
{
    protected ?AbstractValidationHandler $successor = null;

    public function setSuccessor(AbstractValidationHandler $handler): void
    {
        $this->successor = $handler;
    }

    public function handle(array $data): array
    {
        $data = $this->validate($data);

        if ($this->successor) {
            return $this->successor->handle($data);
        }

        return $data;
    }

    abstract protected function validate(array $data): array;
}

// app/Core/Validation/Handlers/InputSanitizationHandler.php
<?php

namespace App\Core\Validation\Handlers;

use App\Core\Validation\Handlers\AbstractValidationHandler;

class InputSanitizationHandler extends AbstractValidationHandler
{
    protected function validate(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return $this->sanitizeString($value);
            } elseif (is_array($value)) {
                return $this->validate($value);
            }
            return $value;
        }, $data);
    }

    private function sanitizeString(string $value): string
    {
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return trim($value);
    }
}

// app/Core/Validation/Handlers/TypeValidationHandler.php
<?php

namespace App\Core\Validation\Handlers;

use App\Core\Validation\Handlers\AbstractValidationHandler;
use App\Core\Validation\Exceptions\ValidationException;

class TypeValidationHandler extends AbstractValidationHandler
{
    private array $types;

    public function __construct(array $types)
    {
        $this->types = $types;
    }

    protected function validate(array $data): array
    {
        $errors = [];

        foreach ($this->types as $field => $type) {
            if (isset($data[$field]) && !$this->validateType($data[$field], $type)) {
                $errors[$field] = "Field must be of type {$type}";
            }
        }

        if (!empty($errors)) {
            throw new ValidationException("Type validation failed", $errors);
        }

        return $data;
    }

    private function validateType($value, string $type): bool
    {
        return match($type) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'ip' => filter_var($value, FILTER_VALIDATE_IP) !== false,
            default => true
        };
    }
}

// app/Core/Validation/Handlers/RangeValidationHandler.php
<?php

namespace App\Core\Validation\Handlers;

use App\Core\Validation\Handlers\AbstractValidationHandler;
use App\Core\Validation\Exceptions\ValidationException;

class RangeValidationHandler extends AbstractValidationHandler
{
    private array $ranges;

    public function __construct(array $ranges)
    {
        $this->ranges = $ranges;
    }

    protected function validate(array $data): array
    {
        $errors = [];

        foreach ($this->ranges as $field => $range) {
            if (isset($data[$field])) {
                $value = $data[$field];
                $min = $range['min'] ?? null;
                $max = $range['max'] ?? null;

                if ($min !== null && $value < $min) {
                    $errors[$field] = "Value must be at least {$min}";
                }

                if ($max !== null && $value > $max) {
                    $errors[$field] = "Value must be at most {$max}";
                }
            }
        }

        if (!empty($errors)) {
            throw new ValidationException("Range validation failed", $errors);
        }

        return $data;
    }
}

// app/Core/Validation/Handlers/CustomValidationHandler.php
<?php

namespace App\Core\Validation\Handlers;

use App\Core\Validation\Handlers\AbstractValidationHandler;
use App\Core\Validation\Exceptions\ValidationException;

class CustomValidationHandler extends AbstractValidationHandler
{
    private array $rules;

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    protected function validate(array $data): array
    {
        $errors = [];

        foreach ($this->rules as $field => $rule) {
            if (isset($data[$field]) && !$rule($data[$field])) {
                $errors[$field] = "Custom validation failed";
            }
        }

        if (!empty($errors)) {
            throw new ValidationException("Custom validation failed", $errors);
        }

        return $data;
    }
}