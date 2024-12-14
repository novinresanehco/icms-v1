<?php

namespace App\Core\UI;

abstract class UIComponentBase implements UIComponentInterface
{
    protected array $props = [];
    protected array $context = [];
    protected array $schema = [];
    protected array $security = [];

    public function __construct()
    {
        $this->initializeSchema();
        $this->initializeSecurity();
    }

    abstract protected function initializeSchema(): void;
    abstract protected function initializeSecurity(): void;
    abstract protected function renderContent(): string;

    public function render(array $props = [], array $context = []): string
    {
        $this->props = $props;
        $this->context = $context;

        try {
            // Pre-render validation
            $this->validatePreRender();

            // Render content
            $output = $this->renderContent();

            // Post-render validation
            $this->validatePostRender($output);

            return $output;

        } catch (\Exception $e) {
            throw new UIException("Component render failed", 0, $e);
        }
    }

    public function getSchema(): array
    {
        return $this->schema;
    }

    public function getSecurityContext(): array
    {
        return $this->security;
    }

    protected function validatePreRender(): void
    {
        // Validate props against schema
        foreach ($this->schema as $key => $rules) {
            if (!isset($this->props[$key]) && $rules['required'] ?? false) {
                throw new ValidationException("Missing required prop: {$key}");
            }

            if (isset($this->props[$key])) {
                $this->validateProp($key, $this->props[$key], $rules);
            }
        }

        // Validate context
        if (!isset($this->context['user_id'])) {
            throw new SecurityException("Missing user context");
        }
    }

    protected function validatePostRender(string $output): void
    {
        // Check output length
        if (strlen($output) > 1000000) { // 1MB limit
            throw new UIException("Component output too large");
        }

        // Validate structure
        if (!$this->isValidStructure($output)) {
            throw new UIException("Invalid component structure");
        }
    }

    protected function validateProp(string $key, $value, array $rules): void
    {
        // Type validation
        if (isset($rules['type'])) {
            $type = gettype($value);
            if ($type !== $rules['type']) {
                throw new ValidationException(
                    "Invalid type for prop {$key}: expected {$rules['type']}, got {$type}"
                );
            }
        }

        // Pattern validation for strings
        if (isset($rules['pattern']) && is_string($value)) {
            if (!preg_match($rules['pattern'], $value)) {
                throw new ValidationException(
                    "Invalid format for prop {$key}"
                );
            }
        }

        // Range validation for numbers
        if (isset($rules['range']) && is_numeric($value)) {
            [$min, $max] = $rules['range'];
            if ($value < $min || $value > $max) {
                throw new ValidationException(
                    "Value out of range for prop {$key}: must be between {$min} and {$max}"
                );
            }
        }

        // Custom validation
        if (isset($rules['validate']) && is_callable($rules['validate'])) {
            if (!$rules['validate']($value)) {
                throw new ValidationException(
                    "Validation failed for prop {$key}"
                );
            }
        }
    }

    protected function isValidStructure(string $output): bool
    {
        // Basic structure validation
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $result = $doc->loadHTML($output, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        if (!$result) {
            return false;
        }

        // Check for prohibited elements
        $prohibited = ['script', 'iframe', 'object', 'embed'];
        foreach ($prohibited as $tag) {
            if ($doc->getElementsByTagName($tag)->length > 0) {
                return false;
            }
        }

        return true;
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5);
    }

    protected function getUserId(): string
    {
        return $this->context['user_id'] ?? '';
    }

    protected function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->context['permissions'] ?? []);
    }

    protected function hasRole(string $role): bool
    {
        return in_array($role, $this->context['roles'] ?? []);
    }
}
