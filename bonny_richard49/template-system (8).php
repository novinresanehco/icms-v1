<?php
namespace App\Core\Template;

class TemplateManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private TemplateCompiler $compiler;

    public function render(string $template, array $data = []): string
    {
        $key = "template.{$template}.".md5(serialize($data));
        
        return $this->cache->remember($key, function() use ($template, $data) {
            $compiled = $this->compiler->compile($template);
            return $this->renderWithSecurity($compiled, $data);
        });
    }

    private function renderWithSecurity(string $template, array $data): string
    {
        try {
            $this->security->validateTemplateData($data);
            return $this->compiler->render($template, $data);
        } catch (\Exception $e) {
            throw new TemplateException('Template render failed', 0, $e);
        }
    }
}

class TemplateCompiler
{
    private array $components = [];
    private SecurityManager $security;

    public function compile(string $template): string
    {
        try {
            $template = $this->validateTemplate($template);
            $template = $this->compileIncludes($template);
            $template = $this->compileComponents($template);
            $template = $this->compilePHP($template);
            return $template;
        } catch (\Exception $e) {
            throw new CompilationException('Template compilation failed', 0, $e);
        }
    }

    private function validateTemplate(string $template): string
    {
        if (!$this->security->validateTemplate($template)) {
            throw new SecurityException('Template validation failed');
        }
        return $template;
    }

    private function compileIncludes(string $template): string
    {
        return preg_replace_callback('/@include\(\'([^\']+)\'\)/', function($matches) {
            return $this->validateTemplate(file_get_contents($matches[1]));
        }, $template);
    }

    public function registerComponent(string $name, callable $component): void
    {
        $this->components[$name] = $component;
    }
}

class ThemeManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private array $themes = [];

    public function activateTheme(string $theme): bool
    {
        if (!isset($this->themes[$theme])) {
            throw new ThemeException('Theme not found');
        }

        try {
            $this->security->validateTheme($theme);
            $this->cache->tags(['themes'])->flush();
            return true;
        } catch (\Exception $e) {
            throw new ThemeException('Theme activation failed', 0, $e);
        }
    }

    public function registerTheme(string $name, array $config): void
    {
        $this->themes[$name] = $config;
    }
}

class ComponentRegistry
{
    private array $components = [];
    private SecurityManager $security;
    private CacheManager $cache;

    public function register(string $name, string $path): void
    {
        if (!$this->security->validateComponent($path)) {
            throw new SecurityException('Invalid component');
        }
        $this->components[$name] = $path;
        $this->cache->tags(['components'])->flush();
    }

    public function render(string $name, array $data = []): string
    {
        if (!isset($this->components[$name])) {
            throw new ComponentException('Component not found');
        }

        return $this->cache->tags(['components'])->remember(
            "component.{$name}.".md5(serialize($data)),
            fn() => $this->renderComponent($name, $data)
        );
    }

    private function renderComponent(string $name, array $data): string
    {
        try {
            $this->security->validateComponentData($data);
            return include $this->components[$name];
        } catch (\Exception $e) {
            throw new ComponentException('Component render failed', 0, $e);
        }
    }
}