// File: app/Core/Validation/Sanitization/SanitizationManager.php
<?php

namespace App\Core\Validation\Sanitization;

class SanitizationManager
{
    protected SanitizerRegistry $registry;
    protected SanitizationConfig $config;

    public function sanitize(array $data, array $rules): array
    {
        $sanitized = [];

        foreach ($data as $field => $value) {
            if (isset($rules[$field])) {
                $sanitized[$field] = $this->sanitizeField($value, $rules[$field]);
            } else {
                $sanitized[$field] = $value;
            }
        }

        return $sanitized;
    }

    protected function sanitizeField($value, array $rules): mixed
    {
        foreach ($rules as $rule) {
            $sanitizer = $this->registry->getSanitizer($rule);
            $value = $sanitizer->sanitize($value);
        }

        return $value;
    }

    public function addSanitizer(string $name, Sanitizer $sanitizer): void
    {
        $this->registry->register($name, $sanitizer);
    }
}

// File: app/Core/Validation/Sanitization/SanitizerRegistry.php
<?php

namespace App\Core\Validation\Sanitization;

class SanitizerRegistry
{
    protected array $sanitizers = [];
    protected SanitizerValidator $validator;

    public function register(string $name, Sanitizer $sanitizer): void
    {
        $this->validator->validate($sanitizer);
        $this->sanitizers[$name] = $sanitizer;
    }

    public function getSanitizer(string $name): Sanitizer
    {
        if (!isset($this->sanitizers[$name])) {
            throw new SanitizerNotFoundException("Sanitizer not found: {$name}");
        }

        return $this->sanitizers[$name];
    }
}
