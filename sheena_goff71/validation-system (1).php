<?php

namespace App\Core\Template\Validation;

class ValidationManager implements ValidationInterface 
{
    private SecurityManager $security;
    private array $rules;

    public function __construct(SecurityManager $security) 
    {
        $this->security = $security;
        $this->rules = [
            'template' => new TemplateValidator(),
            'component' => new ComponentValidator(),
            'media' => new MediaValidator(),
            'content' => new ContentValidator()
        ];
    }

    public function validateTemplate(string $name, array $data): void 
    {
        DB::transaction(function() use ($name, $data) {
            $this->rules['template']->validate([
                'name' => $name,
                'data' => $data,
                'context' => $this->security->getCurrentContext()
            ]);
        });
    }

    public function validateComponent(string $type, array $props): void 
    {
        $this->rules['component']->validate([
            'type' => $type,
            'props' => $props,
            'allowedTypes' => $this->security->getAllowedComponentTypes()
        ]);
    }

    public function validateMedia(array $media): void 
    {
        foreach ($media as $item) {
            $this->rules['media']->validate([
                'item' => $item,
                'allowedTypes' => ['image', 'video', 'document'],
                'maxSize' => config('media.max_size')
            ]);
        }
    }
}

class TemplateValidator implements ValidatorInterface 
{
    public function validate(array $data): void 
    {
        if (!$this->isValidTemplateName($data['name'])) {
            throw new ValidationException('Invalid template name');
        }

        if (!$this->isValidTemplateData($data['data'])) {
            throw new ValidationException('Invalid template data');
        }

        if (!$this->isValidTemplateContext($data['context'])) {
            throw new ValidationException('Invalid template context');
        }
    }

    private function isValidTemplateName(string $name): bool 
    {
        return preg_match('/^[a-zA-Z0-9\-\_\.]+$/', $name);
    }

    private function isValidTemplateData(array $data): bool 
    {
        return count(array_filter($data, fn($item) => 
            !is_scalar($item) && !is_array($item))) === 0;
    }

    private function isValidTemplateContext(array $context): bool 
    {
        return isset($context['user']) && isset($context['permissions']);
    }
}

class ComponentValidator implements ValidatorInterface 
{
    public function validate(array $data): void 
    {
        if (!in_array($data['type'], $data['allowedTypes'])) {
            throw new ValidationException('Invalid component type');
        }

        $this->validateProps($data['type'], $data['props']);
    }

    private function validateProps(string $type, array $props): void 
    {
        $schema = $this->getPropsSchema($type);
        foreach ($schema as $prop => $rule) {
            if ($rule['required'] && !isset($props[$prop])) {
                throw new ValidationException("Missing required prop: {$prop}");
            }
            if (isset($props[$prop])) {
                $this->validatePropValue($props[$prop], $rule);
            }
        }
    }

    private function validatePropValue($value, array $rule): void 
    {
        if (!$this->matchesType($value, $rule['type'])) {
            throw new ValidationException("Invalid prop type: {$rule['type']}");
        }
    }
}

class MediaValidator implements ValidatorInterface 
{
    public function validate(array $data): void 
    {
        $item = $data['item'];
        
        if (!in_array($item['type'], $data['allowedTypes'])) {
            throw new ValidationException('Invalid media type');
        }

        if ($item['size'] > $data['maxSize']) {
            throw new ValidationException('Media size exceeds limit');
        }

        if (!$this->isSecureMediaSource($item['src'])) {
            throw new ValidationException('Insecure media source');
        }
    }

    private function isSecureMediaSource(string $src): bool 
    {
        return filter_var($src, FILTER_VALIDATE_URL) && 
               parse_url($src, PHP_URL_SCHEME) === 'https';
    }
}

interface ValidatorInterface 
{
    public function validate(array $data): void;
}

interface ValidationInterface 
{
    public function validateTemplate(string $name, array $data): void;
    public function validateComponent(string $type, array $props): void;
    public function validateMedia(array $media): void;
}
