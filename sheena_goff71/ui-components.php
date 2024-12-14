namespace App\Core\UI\Components;

class ComponentRegistry implements ComponentRegistryInterface 
{
    private array $components = [];
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
    }

    public function register(string $name, UIComponent $component): void 
    {
        if (!$this->validator->validateComponent($component)) {
            throw new ComponentException("Invalid component: $name");
        }

        $this->components[$name] = $component;
    }

    public function render(string $name, array $props = []): string 
    {
        if (!isset($this->components[$name])) {
            throw new ComponentNotFoundException($name);
        }

        $cacheKey = $this->generateCacheKey($name, $props);

        return $this->cache->remember($cacheKey, function() use ($name, $props) {
            $component = $this->components[$name];
            
            // Validate props
            $validatedProps = $this->validator->validateProps(
                $props,
                $component->getPropsSchema()
            );

            // Render with security checks
            $rendered = $component->render($validatedProps);
            
            return $this->security->sanitizeOutput($rendered);
        });
    }

    private function generateCacheKey(string $name, array $props): string 
    {
        return hash('sha256', $name . serialize($props));
    }
}

abstract class UIComponent 
{
    protected TemplateManager $templates;
    protected SecurityManager $security;

    abstract public function render(array $props): string;
    abstract public function getPropsSchema(): array;

    protected function validateProps(array $props, array $schema): array 
    {
        foreach ($schema as $key => $rules) {
            if (!isset($props[$key]) && !$rules['optional']) {
                throw new PropValidationException("Missing required prop: $key");
            }
        }
        return $props;
    }
}

class FormComponent extends UIComponent 
{
    public function render(array $props): string 
    {
        $validatedProps = $this->validateProps($props, $this->getPropsSchema());
        
        return $this->templates->render('components/form', [
            'action' => $this->security->sanitizeUrl($validatedProps['action']),
            'method' => $validatedProps['method'],
            'fields' => $this->processFields($validatedProps['fields']),
            'csrf' => $this->security->generateCsrfToken()
        ]);
    }

    public function getPropsSchema(): array 
    {
        return [
            'action' => ['type' => 'string', 'optional' => false],
            'method' => ['type' => 'string', 'optional' => false],
            'fields' => ['type' => 'array', 'optional' => false]
        ];
    }

    private function processFields(array $fields): array 
    {
        return array_map(function($field) {
            return $this->security->sanitizeField($field);
        }, $fields);
    }
}

class GridComponent extends UIComponent 
{
    public function render(array $props): string 
    {
        $validatedProps = $this->validateProps($props, $this->getPropsSchema());
        
        return $this->templates->render('components/grid', [
            'items' => $this->processItems($validatedProps['items']),
            'columns' => $validatedProps['columns'],
            'gap' => $validatedProps['gap']
        ]);
    }

    public function getPropsSchema(): array 
    {
        return [
            'items' => ['type' => 'array', 'optional' => false],
            'columns' => ['type' => 'integer', 'optional' => true, 'default' => 3],
            'gap' => ['type' => 'string', 'optional' => true, 'default' => '1rem']
        ];
    }

    private function processItems(array $items): array 
    {
        return array_map(function($item) {
            return $this->security->sanitizeGridItem($item);
        }, $items);
    }
}

interface ComponentRegistryInterface 
{
    public function register(string $name, UIComponent $component): void;
    public function render(string $name, array $props = []): string;
}
