<?php

namespace App\Core\Template\Components;

class UIComponentManager 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private array $registeredComponents = [];

    public function __construct(SecurityManager $security, ValidationService $validator) 
    {
        $this->security = $security;
        $this->validator = $validator;
    }

    public function registerComponent(string $name, UIComponent $component): void 
    {
        $this->validator->validateComponentName($name);
        $this->registeredComponents[$name] = $component;
    }

    public function render(string $name, array $props = []): string 
    {
        $component = $this->getComponent($name);
        $validatedProps = $this->validator->validateProps($props, $component->getSchema());
        
        return $component->render($validatedProps);
    }

    private function getComponent(string $name): UIComponent 
    {
        if (!isset($this->registeredComponents[$name])) {
            throw new ComponentNotFoundException($name);
        }
        return $this->registeredComponents[$name];
    }
}

abstract class UIComponent 
{
    abstract public function render(array $props): string;
    abstract public function getSchema(): array;
    
    protected function validateSecurity(array $props): void 
    {
        // Security validation for all components
    }
}

class CardComponent extends UIComponent 
{
    public function render(array $props): string 
    {
        $this->validateSecurity($props);
        
        return view('components.card', $props)->render();
    }

    public function getSchema(): array 
    {
        return [
            'title' => 'string|required',
            'content' => 'string|required',
            'image' => 'string|nullable',
            'actions' => 'array|nullable'
        ];
    }
}
