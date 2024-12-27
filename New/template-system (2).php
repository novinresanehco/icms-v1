<?php

namespace App\Core\Template;

class TemplateEngine implements TemplateEngineInterface
{
    private TemplateLoader $loader;
    private TemplateCompiler $compiler;
    private CacheManager $cache;
    private array $globals = [];

    public function render(string $template, array $data = []): string
    {
        $cacheKey = $this->getCacheKey($template, $data);
        
        return $this->cache->remember($cacheKey, function() use ($template, $data) {
            $source = $this->loader->load($template);
            $compiled = $this->compiler->compile($source);
            return $this->evaluate($compiled, array_merge($this->globals, $data));
        });
    }

    public function extend(string $template, array $blocks): Template
    {
        $parent = $this->loader->load($template);
        return new Template($parent, $blocks);
    }

    private function evaluate(CompiledTemplate $template, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        include $template->getPath();
        return ob_get_clean();
    }

    private function getCacheKey(string $template, array $data): string
    {
        return 'template:' . md5($template . serialize($data));
    }
}

class TemplateCompiler
{
    private string $cachePath;
    private array $directives = [];

    public function compile(string $source): CompiledTemplate
    {
        $path = $this->getCachePath(md5($source));
        
        if (!$this->isExpired($path, $source)) {
            return new CompiledTemplate($path);
        }

        $compiled = $this->compileString($source);
        file_put_contents($path, $compiled);
        
        return new CompiledTemplate($path);
    }

    private function compileString(string $source): string
    {
        $result = $source;

        foreach ($this->directives as $type => $compiler) {
            $result = $compiler->compile($result);
        }

        return $result;
    }
}

class ThemeManager implements ThemeManagerInterface
{
    private array $themes = [];
    private ?string $active = null;
    private CacheManager $cache;

    public function register(string $name, Theme $theme): void
    {
        $this->themes[$name] = $theme;
        $this->cache->tags(['themes'])->flush();
    }

    public function activate(string $name): void
    {
        if (!isset($this->themes[$name])) {
            throw new ThemeNotFoundException($name);
        }

        $this->active = $name;
        $this->cache->tags(['themes'])->flush();
    }

    public function getActive(): Theme
    {
        if (!$this->active) {
            throw new NoActiveThemeException();
        }

        return $this->themes[$this->active];
    }
}

class Theme
{
    private string $path;
    private array $config;
    private array $assets = [];

    public function addAsset(string $type, string $path): void
    {
        $this->assets[$type][] = $path;
    }

    public function getAssets(string $type): array
    {
        return $this->assets[$type] ?? [];
    }

    public function getPath(string $file = ''): string
    {
        return $this->path . ($file ? DIRECTORY_SEPARATOR . $file : '');
    }

    public function getConfig(string $key, $default = null)
    {
        return Arr::get($this->config, $key, $default);
    }
}

class AssetManager implements AssetManagerInterface
{
    private ThemeManager $themes;
    private CacheManager $cache;
    private string $publicPath;

    public function publishThemeAssets(Theme $theme): void
    {
        foreach ($theme->getAssets('css') as $path) {
            $this->publish($theme->getPath($path));
        }

        foreach ($theme->getAssets('js') as $path) {
            $this->publish($theme->getPath($path));
        }
    }

    private function publish(string $path): void
    {
        $destination = $this->publicPath . '/' . md5($path) . basename($path);
        copy($path, $destination);
    }
}

class BlockManager implements BlockManagerInterface
{
    private array $blocks = [];
    private CacheManager $cache;

    public function register(string $name, callable $renderer): void
    {
        $this->blocks[$name] = $renderer;
        $this->cache->tags(['blocks'])->flush();
    }

    public function render(string $name, array $data = []): string
    {
        if (!isset($this->blocks[$name])) {
            throw new BlockNotFoundException($name);
        }

        return $this->cache->tags(['blocks'])->remember("block:$name", function() use ($name, $data) {
            return call_user_func($this->blocks[$name], $data);
        });
    }
}
