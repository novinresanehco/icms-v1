<?php

namespace App\Core\Template\Layout;

class LayoutManager implements LayoutManagerInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private array $layouts = [];

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache
    ) {
        $this->security = $security;
        $this->cache = $cache;
    }

    public function render(string $layoutName, array $sections): string 
    {
        $this->security->validateLayout($layoutName);
        $cacheKey = "layout:{$layoutName}:" . md5(serialize($sections));

        return $this->cache->remember($cacheKey, function() use ($layoutName, $sections) {
            $layout = $this->compileLayout($layoutName, $sections);
            $this->security->validateOutput($layout);
            return $layout;
        });
    }

    private function compileLayout(string $layout, array $sections): string 
    {
        $layout = $this->layouts[$layout] ?? throw new LayoutNotFoundException();
        
        return $layout->compile([
            'content' => $this->security->sanitize($sections['content'] ?? ''),
            'header' => $this->security->sanitize($sections['header'] ?? ''),
            'footer' => $this->security->sanitize($sections['footer'] ?? '')
        ]);
    }
}

class ComponentRenderer implements ComponentRendererInterface 
{
    private SecurityManagerInterface $security;
    private array $components = [];

    public function render(string $name, array $props = []): string 
    {
        $this->security->validateComponent($name);
        $component = $this->components[$name] ?? throw new ComponentNotFoundException();
        
        $sanitizedProps = $this->security->sanitizeProps($props);
        return $component->render($sanitizedProps);
    }
}

interface LayoutManagerInterface {
    public function render(string $layoutName, array $sections): string;
}

interface ComponentRendererInterface {
    public function render(string $name, array $props = []): string;
}
