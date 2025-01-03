<?php

namespace App\Core\Validation;

class ValidationService implements ValidationInterface
{
    private array $rules = [];
    private array $messages = [];

    public function validate(array $data): array
    {
        foreach ($this->rules as $field => $rule) {
            if (!$this->validateField($data[$field], $rule)) {
                throw new ValidationException($this->messages[$field]);
            }
        }
        return $data;
    }

    public function validateRequest(Request $request): bool
    {
        return $this->validate($request->all());
    }

    public function validateTemplate(string $template): void
    {
        if (!$this->isSecureTemplate($template)) {
            throw new ValidationException('Invalid template');
        }
    }

    private function validateField($value, $rule): bool
    {
        return match ($rule) {
            'required' => !empty($value),
            'string' => is_string($value),
            'array' => is_array($value),
            default => true
        };
    }

    private function isSecureTemplate(string $template): bool
    {
        return !preg_match('/{{.*}}/', $template);
    }
}
