```php
namespace App\Core\UI;

final class ComponentSystem 
{
    private ComponentFactory $factory;
    private SecurityContext $security;
    private RenderPipeline $pipeline;

    public function createComponent(string $type, array $config): UIComponent 
    {
        $this->security->validateComponentType($type);
        return $this->factory->create($type, $config);
    }

    public function renderComponent(UIComponent $component, array $props): string 
    {
        $this->security->validateComponentRender($component, $props);
        
        return $this->pipeline->process(
            $component,
            $props,
            $this->security->getContext()
        );
    }
}

abstract class BaseUIComponent 
{
    protected SecurityContext $security;
    protected ValidationRules $rules;
    protected RenderContext $context;

    abstract protected function validateProps(array $props): void;
    abstract protected function prepareData(array $props): array;
    abstract protected function generateTemplate(array $data): string;
    abstract protected function getRenderRules(): array;
}

final class RenderPipeline 
{
    private array $middleware = [];
    private RenderContext $context;

    public function process(UIComponent $component, array $props, SecurityContext $context): string 
    {
        $this->context = $context;

        $pipeline = array_reduce(
            array_reverse($this->middleware),
            fn($stack, $middleware) => fn($component, $props) => 
                $middleware->process($component, $props, $stack),
            fn($component, $props) => $component->render($props)
        );

        return $pipeline($component, $props);
    }
}
```
