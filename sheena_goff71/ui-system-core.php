namespace App\Core\UI;

class UIComponentSystem implements ComponentSystemInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private RenderEngine $renderer;
    private array $registeredComponents = [];

    public function registerComponent(string $name, array $config): void 
    {
        if (!$this->validator->validateComponentConfig($config)) {
            throw new ComponentConfigException("Invalid component configuration: $name");
        }

        $this->registeredComponents[$name] = array_merge($config, [
            'security' => $this->buildSecurityConfig($config),
            'validation' => $this->buildValidationRules($config)
        ]);
    }

    public function render(string $name, array $props): string 
    {
        $component = $this->getComponent($name);
        
        $validatedProps = $this->validator->validateProps(
            $props,
            $component['validation']
        );

        return $this->renderer->renderSecure(
            $component['template'],
            $validatedProps,
            $component['security']
        );
    }

    private function buildSecurityConfig(array $config): array 
    {
        return [
            'escapeOutput' => $config['escapeOutput'] ?? true,
            'sanitizeProps' => $config['sanitizeProps'] ?? true,
            'validateUrls' => $config['validateUrls'] ?? true,
            'xssProtection' => $config['xssProtection'] ?? true
        ];
    }

    private function buildValidationRules(array $config): array 
    {
        return array_merge(
            $config['validation'] ?? [],
            [
                'required' => $config['required'] ?? [],
                'optional' => $config['optional'] ?? [],
                'types' => $config['types'] ?? []
            ]
        );
    }

    private function getComponent(string $name): array 
    {
        if (!isset($this->registeredComponents[$name])) {
            throw new ComponentNotFoundException("Component not found: $name");
        }
        return $this->registeredComponents[$name];
    }
}

interface ComponentSystemInterface 
{
    public function registerComponent(string $name, array $config): void;
    public function render(string $name, array $props): string;
}

class RenderEngine 
{
    private SecurityManager $security;
    private CacheManager $cache;

    public function renderSecure(
        string $template,
        array $data,
        array $security
    ): string {
        $this->security->validateRenderContext($template, $data);
        
        return $this->cache->remember(
            $this->getCacheKey($template, $data),
            fn() => $this->executeRender($template, $data, $security)
        );
    }

    private function executeRender(
        string $template,
        array $data,
        array $security
    ): string {
        $rendered = $this->renderTemplate($template, $data);
        
        if ($security['escapeOutput']) {
            $rendered = $this->security->escapeOutput($rendered);
        }
        
        if ($security['xssProtection']) {
            $rendered = $this->security->preventXSS($rendered);
        }
        
        return $rendered;
    }

    private function getCacheKey(string $template, array $data): string 
    {
        return hash('xxh3', $template . serialize($data));
    }
}

class ComponentValidator 
{
    public function validateComponentConfig(array $config): bool 
    {
        return isset($config['template'])
            && is_string($config['template'])
            && $this->validateSecurityOptions($config)
            && $this->validateValidationRules($config);
    }

    public function validateProps(array $props, array $rules): array 
    {
        foreach ($rules['required'] as $prop) {
            if (!isset($props[$prop])) {
                throw new ValidationException("Missing required prop: $prop");
            }
        }

        foreach ($props as $key => $value) {
            if (isset($rules['types'][$key])) {
                $this->validatePropType($value, $rules['types'][$key], $key);
            }
        }

        return $props;
    }

    private function validatePropType($value, string $type, string $key): void 
    {
        $valid = match($type) {
            'string' => is_string($value),
            'number' => is_numeric($value),
            'array' => is_array($value),
            'boolean' => is_bool($value),
            default => false
        };

        if (!$valid) {
            throw new ValidationException(
                "Invalid type for prop '$key'. Expected $type"
            );
        }
    }
}
