<?php
namespace App\Core\Template;

class TemplateManager implements TemplateInterface 
{
    private TemplateRepository $templates;
    private CacheManager $cache;
    private CompilerInterface $compiler;

    public function render(string $template, array $data = []): string 
    {
        return $this->cache->remember(
            "template.{$template}." . md5(serialize($data)),
            3600,
            fn() => $this->compile($template, $data)
        );
    }

    public function compile(string $template, array $data = []): string 
    {
        $template = $this->templates->findOrFail($template);
        return $this->compiler->compile($template->getContent(), $data);
    }

    public function registerComponent(string $name, callable $component): void 
    {
        $this->compiler->registerComponent($name, $component);
    }

    public function clearCache(): void 
    {
        $this->cache->tags(['templates'])->flush();
    }
}

class TemplateCompiler implements CompilerInterface 
{
    private array $components = [];

    public function compile(string $content, array $data): string 
    {
        $content = $this->compileComponents($content);
        $content = $this->compileData($content, $data);
        return $this->compileIncludes($content);
    }

    protected function compileComponents(string $content): string 
    {
        foreach ($this->components as $name => $component) {
            $content = preg_replace_callback(
                "/@component\('$name'\)(.*?)@endcomponent/s",
                fn($matches) => $component($matches[1]),
                $content
            );
        }
        return $content;
    }

    protected function compileData(string $content, array $data): string 
    {
        foreach ($data as $key => $value) {
            $content = str_replace(
                "{{ $$key }}",
                htmlspecialchars($value, ENT_QUOTES),
                $content
            );
        }
        return $content;
    }

    protected function compileIncludes(string $content): string
    {
        return preg_replace_callback(
            "/@include\('([^']+)'\)/",
            fn($matches) => $this->loadInclude($matches[1]),
            $content
        );
    }

    public function registerComponent(string $name, callable $component): void
    {
        $this->components[$name] = $component;
    }

    protected function loadInclude(string $template): string 
    {
        if (!file_exists($path = resource_path("views/$template.blade.php"))) {
            throw new TemplateNotFoundException("Template not found: $template");
        }
        return file_get_contents($path);
    }
}
