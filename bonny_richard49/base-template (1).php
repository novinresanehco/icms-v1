<?php

namespace App\Core\Template;

class TemplateManager
{
    private $cache;
    private $loader;
    private $compiler;

    public function render(string $template, array $data = []): string 
    {
        $compiled = $this->cache->remember(
            "template:$template",
            3600,
            fn() => $this->compiler->compile(
                $this->loader->load($template)
            )
        );

        return $this->evaluate($compiled, $data);
    }

    private function evaluate(string $compiled, array $data): string
    {
        extract($data);
        ob_start();
        eval('?>' . $compiled);
        return ob_get_clean();
    }
}

class TemplateCompiler
{
    private $directives = [];

    public function compile(string $template): string
    {
        $template = $this->compileDirectives($template);
        $template = $this->compileEchos($template);
        return $this->compilePhp($template);
    }

    private function compileDirectives(string $template): string
    {
        foreach ($this->directives as $pattern => $callback) {
            $template = preg_replace_callback($pattern, $callback, $template);
        }
        return $template;
    }

    private function compileEchos(string $template): string
    {
        return preg_replace('/{{(.+?)}}/', '<?php echo htmlspecialchars($1); ?>', $template);
    }

    private function compilePhp(string $template): string
    {
        return preg_replace('/@php(.+?)@endphp/s', '<?php$1?>', $template);
    }
}

class FileLoader
{
    private $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function load(string $template): string
    {
        $path = $this->basePath . '/' . $template . '.php';
        if (!file_exists($path)) {
            throw new \RuntimeException("Template not found: $template");
        }
        return file_get_contents($path);
    }
}

class Layout
{
    private $sections = [];
    private $currentSection;

    public function start(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    public function stop(): void
    {
        if (!$this->currentSection) {
            throw new \RuntimeException('No active section');
        }
        $this->sections[$this->currentSection] = ob_get_clean();
        $this->currentSection = null;
    }

    public function yield(string $name): string
    {
        return $this->sections[$name] ?? '';
    }
}

class Theme
{
    private $name;
    private $path;
    private $config;

    public function getLayout(string $name): string
    {
        return $this->path . '/layouts/' . $name . '.php';
    }

    public function getAsset(string $path): string
    {
        return $this->path . '/assets/' . $path;
    }
}
