<?php

namespace App\Core\Template\Display;

class DisplayComponentRegistry implements DisplayRegistryInterface 
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private array $components = [];

    public function register(string $name, DisplayComponentInterface $component): void 
    {
        $this->security->validateComponentRegistration($name);
        $this->components[$name] = $component;
    }

    public function render(string $name, array $props = []): string 
    {
        $this->security->enforceComponentAccess($name);
        
        return $this->cache->remember(
            "component:{$name}:" . md5(serialize($props)),
            fn() => $this->renderSecure($name, $props)
        );
    }

    private function renderSecure(string $name, array $props): string 
    {
        $component = $this->components[$name] ?? throw new ComponentNotFoundException();
        
        $sanitizedProps = $this->security->sanitizeProps($props);
        $rendered = $component->render($sanitizedProps);
        
        return $this->security->validateOutput($rendered);
    }
}

class SecureContentDisplay implements ContentDisplayInterface 
{
    private SecurityManagerInterface $security;
    private DisplayRegistryInterface $components;
    
    public function content(int $id, string $type = 'default'): string 
    {
        $this->security->enforceContentAccess($id);
        return $this->components->render("content.{$type}", ['id' => $id]);
    }
    
    public function section(string $name, array $data = []): string 
    {
        $this->security->enforceSectionAccess($name);
        return $this->components->render("section.{$name}", $data);
    }
}

interface DisplayRegistryInterface {
    public function register(string $name, DisplayComponentInterface $component): void;
    public function render(string $name, array $props = []): string;
}

interface ContentDisplayInterface {
    public function content(int $id, string $type = 'default'): string;
    public function section(string $name, array $data = []): string;
}

interface DisplayComponentInterface {
    public function render(array $props): string;
}

class ComponentNotFoundException extends \RuntimeException {}
