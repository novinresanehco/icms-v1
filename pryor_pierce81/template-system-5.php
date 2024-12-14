<?php

namespace App\Core\Template;

use App\Core\Template\Contracts\TemplateInterface;
use App\Core\Template\Exceptions\TemplateException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Filesystem\Filesystem;

class TemplateManager implements TemplateInterface
{
    protected Filesystem $filesystem;
    protected TemplateCompiler $compiler;
    protected TemplateCache $cache;
    protected array $config;
    
    public function __construct(
        Filesystem $filesystem,
        TemplateCompiler $compiler,
        TemplateCache $cache
    ) {
        $this->filesystem = $filesystem;
        $this->compiler = $compiler;
        $this->cache = $cache;
        $this->config = config('templates');
    }

    /**
     * Render a template with provided data
     */
    public function render(string $template, array $data = []): string
    {
        try {
            $templatePath = $this->resolveTemplatePath($template);
            $cacheKey = $this->getCacheKey($templatePath);

            if ($this->cache->has($cacheKey)) {
                $compiled = $this->cache->get($cacheKey);
            } else {
                $content = $this->filesystem->get($templatePath);
                $compiled = $this->compiler->compile($content);
                $this->cache->put($cacheKey, $compiled);
            }

            return $this->evaluateTemplate($compiled, $data);
        } catch (\Exception $e) {
            throw new TemplateException("Failed to render template: {$template}", 0, $e);
        }
    }

    /**
     * Compile a template component
     */
    public function compileComponent(string $name, string $content): string
    {
        return $this->compiler->compileComponent($name, $content);
    }

    /**
     * Register a custom directive
     */
    public function directive(string $name, callable $handler): void
    {
        $this->compiler->directive($name, $handler);
    }
}

namespace App\Core\Template;

class TemplateCompiler
{
    protected array $customDirectives = [];
    protected array $components = [];

    /**
     * Compile template content
     */
    public function compile(string $content): string
    {
        $content = $this->compileDirectives($content);
        $content = $this->compileComponents($content);
        $content = $this->compileEchos($content);
        $content = $this->compilePhp($content);
        
        return $content;
    }

    /**
     * Compile custom directives
     */
    protected function compileDirectives(string $content): string
    {
        foreach ($this->customDirectives as $name => $handler) {
            $pattern = "/@{$name}\s*\((.*?)\)/";
            $content = preg_replace_callback($pattern, function($matches) use ($handler) {
                return $handler($matches[1] ?? null);
            }, $content);
        }
        
        return $content;
    }

    /**
     * Compile components
     */
    protected function compileComponents(string $content): string
    {
        foreach ($this->components as $name => $component) {
            $pattern = "/<x-{$name}[^>]*>(.*?)<\/x-{$name}>/s";
            $content = preg_replace_callback($pattern, function($matches) use ($component) {
                return $this->renderComponent($component, $matches[0], $matches[1]);
            }, $content);
        }
        
        return $content;
    }
}

namespace App\Core\Template\Cache;

class TemplateCache
{
    protected CacheManager $cache;
    protected array $config;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
        $this->config = config('templates.cache');
    }

    /**
     * Get compiled template from cache
     */
    public function get(string $key): ?string
    {
        return $this->cache->tags(['templates'])->get($key);
    }

    /**
     * Store compiled template in cache
     */
    public function put(string $key, string $content): void
    {
        $this->cache->tags(['templates'])->put(
            $key,
            $content,
            $this->config['ttl'] ?? 3600
        );
    }

    /**
     * Check if template exists in cache
     */
    public function has(string $key): bool
    {
        return $this->cache->tags(['templates'])->has($key);
    }

    /**
     * Clear template cache
     */
    public function clear(?string $key = null): void
    {
        if ($key) {
            $this->cache->tags(['templates'])->forget($key);
        } else {
            $this->cache->tags(['templates'])->flush();
        }
    }
}

namespace App\Core\Template\Components;

class Component
{
    protected string $name;
    protected string $view;
    protected array $data;

    /**
     * Render the component
     */
    public function render(): string
    {
        return view($this->view, $this->data)->render();
    }

    /**
     * Set component data
     */
    public function withData(array $data): self
    {
        $this->data = array_merge($this->data, $data);
        return $this;
    }

    /**
     * Get component attributes
     */
    public function getAttributes(): array
    {
        return array_diff_key(
            $this->data,
            array_flip($this->except ?? [])
        );
    }
}

namespace App\Core\Template\Facades;

class Theme
{
    protected static ThemeManager $manager;

    /**
     * Get active theme
     */
    public static function active(): string
    {
        return static::$manager->getActiveTheme();
    }

    /**
     * Set active theme
     */
    public static function set(string $theme): void
    {
        static::$manager->setActiveTheme($theme);
    }

    /**
     * Get theme asset URL
     */
    public static function asset(string $path): string
    {
        return static::$manager->getAssetUrl($path);
    }

    /**
     * Get theme view path
     */
    public static function view(string $view): string
    {
        return static::$manager->getViewPath($view);
    }
}
