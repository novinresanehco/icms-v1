<?php

namespace App\Core\UI;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationServiceInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Exception\{UIException, ValidationException};

class UIComponentManager implements UIComponentInterface 
{
    private SecurityManagerInterface $security;
    private ValidationServiceInterface $validator;
    private CacheManagerInterface $cache;
    private array $registeredComponents = [];
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationServiceInterface $validator,
        CacheManagerInterface $cache,
        array $config = []
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function render(string $componentName, array $props = []): string 
    {
        try {
            // Validate component name
            $this->validateComponentName($componentName);

            // Security check
            $this->security->validateAccess('ui:render', $componentName);

            // Validate props
            $validatedProps = $this->validator->validateData($props, $componentName);

            // Get component instance
            $component = $this->getComponent($componentName);

            // Render with security context
            return $this->secureRender($component, $validatedProps);

        } catch (\Exception $e) {
            throw new UIException("Failed to render component: {$componentName}", 0, $e);
        }
    }

    public function registerComponent(string $name, UIComponentInterface $component): void 
    {
        try {
            // Validate component
            $this->validateComponent($component);

            // Register with validation
            $this->registeredComponents[$name] = [
                'component' => $component,
                'validator' => $this->createComponentValidator($component),
                'security' => $this->createComponentSecurity($component)
            ];

        } catch (\Exception $e) {
            throw new UIException("Failed to register component: {$name}", 0, $e);
        }
    }

    private function validateComponentName(string $name): void 
    {
        if (!isset($this->registeredComponents[$name])) {
            throw new UIException("Component not found: {$name}");
        }

        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $name)) {
            throw new ValidationException("Invalid component name: {$name}");
        }
    }

    private function validateComponent(UIComponentInterface $component): void 
    {
        if (!$component->getSchema()) {
            throw new ValidationException("Component must define a schema");
        }

        if (!$component->getSecurityContext()) {
            throw new ValidationException("Component must define security context");
        }
    }

    private function getComponent(string $name): UIComponentInterface 
    {
        if (!isset($this->registeredComponents[$name])) {
            throw new UIException("Component not found: {$name}");
        }

        return $this->registeredComponents[$name]['component'];
    }

    private function secureRender(UIComponentInterface $component, array $props): string 
    {
        // Create secure context
        $context = [
            'user_id' => $this->security->getCurrentUserId(),
            'roles' => $this->security->getCurrentUserRoles(),
            'permissions' => $this->security->getCurrentUserPermissions()
        ];

        // Sanitize props
        $sanitizedProps = $this->sanitizeProps($props);

        // Render with context
        $output = $component->render($sanitizedProps, $context);

        // Sanitize output
        return $this->sanitizeOutput($output);
    }

    private function createComponentValidator(UIComponentInterface $component): callable 
    {
        return function(array $props) use ($component) {
            $schema = $component->getSchema();
            return $this->validator->validateAgainstSchema($props, $schema);
        };
    }

    private function createComponentSecurity(UIComponentInterface $component): array 
    {
        return array_merge(
            $this->config['default_security'],
            $component->getSecurityContext()
        );
    }

    private function sanitizeProps(array $props): array 
    {
        array_walk_recursive($props, function(&$value) {
            if (is_string($value)) {
                $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5);
            }
        });

        return $props;
    }

    private function sanitizeOutput(string $output): string 
    {
        // Remove potentially dangerous content
        $output = preg_replace(
            $this->config['dangerous_patterns'],
            '',
            $output
        );

        return $output;
    }

    private function getDefaultConfig(): array 
    {
        return [
            'default_security' => [
                'csrf' => true,
                'xss' => true,
                'sanitize' => true
            ],
            'dangerous_patterns' => [
                '/<script\b[^>]*>(.*?)<\/script>/is',
                '/on\w+="[^"]*"/is',
                '/javascript:/i'
            ],
            'max_prop_depth' => 5,
            'max_render_time' => 1000 // milliseconds
        ];
    }
}
