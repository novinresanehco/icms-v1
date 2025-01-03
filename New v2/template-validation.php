<?php

namespace App\Core\Template\Validation;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Template\Exceptions\{
    ValidationException,
    TemplateException,
    SecurityException
};

class TemplateValidator
{
    private SecurityManagerInterface $security;
    private array $rules;
    private array $messages;
    
    public function __construct(
        SecurityManagerInterface $security,
        array $rules = [],
        array $messages = []
    ) {
        $this->security = $security;
        $this->rules = $rules;
        $this->messages = $messages;
    }

    public function validate(array $data): array
    {
        return $this->security->executeInContext(function() use ($data) {
            $validated = [];
            
            foreach ($this->rules as $field => $rules) {
                if (!isset($data[$field]) && $this->isRequired($rules)) {
                    throw new ValidationException(
                        $this->getMessage($field, 'required')
                    );
                }

                $value = $data[$field] ?? null;
                
                foreach ($rules as $rule) {
                    $this->validateRule($field, $value, $rule);
                }
                
                $validated[$field] = $value;
            }
            
            return $validated;
        });
    }

    public function validateTemplate(string $template): void
    {
        if (!$this->security->validateResource($template)) {
            throw new SecurityException("Invalid template access: {$template}");
        }

        if (!file_exists($template)) {
            throw new TemplateException("Template not found: {$template}");
        }

        $content = file_get_contents($template);
        
        if ($this->containsPhpTags($content)) {
            throw new SecurityException("Template contains PHP tags: {$template}");
        }

        if ($this->containsUnsafeDirectives($content)) {
            throw new SecurityException("Template contains unsafe directives: {$template}");
        }
    }

    public function validateCompiled(string $compiled): void
    {
        if (!$this->security->validateFile($compiled)) {
            throw new SecurityException("Invalid compiled template: {$compiled}");
        }

        if (!file_exists($compiled)) {
            throw new TemplateException("Compiled template not found: {$compiled}");
        }
    }

    private function validateRule(string $field, $value, string $rule): void
    {
        [$rule, $parameters] = $this->parseRule($rule);

        switch ($rule) {
            case 'required':
                if (empty($value)) {
                    throw new ValidationException(
                        $this->getMessage($field, 'required')
                    );
                }
                break;

            case 'string':
                if (!is_string($value)) {
                    throw new ValidationException(
                        $this->getMessage($field, 'string')
                    );
                }
                break;

            case 'array':
                if (!is_array($value)) {
                    throw new ValidationException(
                        $this->getMessage($field, 'array')
                    );
                }
                break;

            case 'max':
                if (is_string($value) && strlen($value) > $parameters[0]) {
                    throw new ValidationException(
                        $this->getMessage($field, 'max')
                    );
                }
                break;

            case 'min':
                if (is_string($value) && strlen($value) < $parameters[0]) {
                    throw new ValidationException(
                        $this->getMessage($field, 'min')
                    );
                }
                break;

            case 'safe':
                if (!$this->security->validateData($field, $value)) {
                    throw new SecurityException(
                        $this->getMessage($field, 'safe')
                    );
                }
                break;
        }
    }

    private function parseRule(string $rule): array
    {
        $parameters = [];

        if (strpos($rule, ':') !== false) {
            [$rule, $paramStr] = explode(':', $rule, 2);
            $parameters = explode(',', $paramStr);
        }

        return [$rule, $parameters];
    }

    private function isRequired(array $rules): bool
    {
        return in_array('required', $rules);
    }

    private function getMessage(string $field, string $rule): string
    {
        $key = "{$field}.{$rule}";
        return $this->messages[$key] ?? "The {$field} field is invalid";
    }

    private function containsPhpTags(string $content): bool
    {
        return preg_match('/<\?php|\?>/', $content) === 1;
    }

    private function containsUnsafeDirectives(string $content): bool
    {
        $unsafe = [
            '@php',
            '@eval',
            '@system'
        ];

        foreach ($unsafe as $directive) {
            if (strpos($content, $directive) !== false) {
                return true;
            }
        }

        return false;
    }
}

class ThemeValidator
{
    private SecurityManagerInterface $security;
    
    public function __construct(SecurityManagerInterface $security)
    {
        $this->security = $security;
    }

    public function validateTheme(string $name, array $config): void
    {
        $this->validateName($name);
        $this->validateConfig($config);
        $this->validatePath($config['path'] ?? null);
        $this->validateTemplates($config['templates'] ?? []);
        $this->validateAssets($config['assets'] ?? []);
    }

    private function validateName(string $name): void
    {
        if (empty($name)) {
            throw new ValidationException('Theme name is required');
        }

        if (!preg_match('/^[a-z0-9\-_]+$/', $name)) {
            throw new ValidationException('Invalid theme name format');
        }
    }

    private function validateConfig(array $config): void
    {
        $required = ['path', 'templates'];

        foreach ($required as $field) {
            if (!isset($config[$field])) {
                throw new ValidationException("Missing required theme config: {$field}");
            }
        }
    }

    private function validatePath(?string $path): void
    {
        if (!$path) {
            throw new ValidationException('Theme path is required');
        }

        if (!$this->security->validatePath($path)) {
            throw new SecurityException("Invalid theme path: {$path}");
        }

        if (!is_dir($path)) {
            throw new ValidationException("Theme directory not found: {$path}");
        }
    }

    private function validateTemplates(array $templates): void
    {
        if (empty($templates)) {
            throw new ValidationException('Theme must have at least one template');
        }

        foreach ($templates as $template) {
            if (!$this->security->validateFile($template)) {
                throw new SecurityException("Invalid template file: {$template}");
            }
        }
    }

    private function validateAssets(array $assets): void
    {
        foreach ($assets as $source => $target) {
            if (!$this->security->validateFile($source)) {
                throw new SecurityException("Invalid asset source: {$source}");
            }

            if (!$this->security->validatePath($target)) {
                throw new SecurityException("Invalid asset target: {$target}"); 
            }
        }
    }
}
