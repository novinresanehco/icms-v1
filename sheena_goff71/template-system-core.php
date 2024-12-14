<?php

namespace App\Core\Template;

use App\Core\Template\Contracts\TemplateInterface;
use App\Core\Template\Exceptions\TemplateException;
use App\Core\Template\Parsers\TemplateParser;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Collection;
use Illuminate\Contracts\View\Factory;

class TemplateManager implements TemplateInterface
{
    protected CacheManager $cache;
    protected TemplateParser $parser;
    protected Factory $viewFactory;
    protected Collection $registeredComponents;
    
    public function __construct(
        CacheManager $cache,
        TemplateParser $parser,
        Factory $viewFactory
    ) {
        $this->cache = $cache;
        $this->parser = $parser;
        $this->viewFactory = $viewFactory;
        $this->registeredComponents = new Collection();
    }

    /**
     * Render a template with provided data
     *
     * @param string $template
     * @param array $data
     * @return string
     * @throws TemplateException
     */
    public function render(string $template, array $data = []): string
    {
        try {
            $cacheKey = $this->generateCacheKey($template, $data);
            
            return $this->cache->remember($cacheKey, 3600, function () use ($template, $data) {
                $compiled = $this->compile($template);
                return $this->viewFactory->make($compiled, $data)->render();
            });
        } catch (\Exception $e) {
            throw new TemplateException("Failed to render template: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Compile template into a view
     *
     * @param string $template
     * @return string
     * @throws TemplateException
     */
    public function compile(string $template): string
    {
        try {
            return $this->parser->parse($template);
        } catch (\Exception $e) {
            throw new TemplateException("Template compilation failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Register a custom component
     *
     * @param string $name
     * @param string $path
     * @return void
     */
    public function registerComponent(string $name, string $path): void
    {
        $this->registeredComponents->put($name, $path);
        $this->viewFactory->addNamespace("components.{$name}", $path);
    }

    /**
     * Generate template content with SEO metadata
     *
     * @param string $view
     * @param array $data
     * @param array $seoData
     * @return string
     */
    public function renderWithSeo(string $view, array $data = [], array $seoData = []): string
    {
        $compiledSeo = $this->compileSeoData($seoData);
        return $this->render($view, array_merge($data, ['seo' => $compiledSeo]));
    }

    /**
     * Check if a component is registered
     *
     * @param string $name
     * @return bool
     */
    public function hasComponent(string $name): bool
    {
        return $this->registeredComponents->has($name);
    }

    /**
     * Get registered component path
     *
     * @param string $name
     * @return string|null
     */
    public function getComponentPath(string $name): ?string
    {
        return $this->registeredComponents->get($name);
    }

    /**
     * Generate a cache key for the template
     *
     * @param string $template
     * @param array $data
     * @return string
     */
    protected function generateCacheKey(string $template, array $data): string
    {
        return sprintf(
            'template:%s:%s',
            md5($template),
            md5(serialize($data))
        );
    }

    /**
     * Compile SEO metadata into HTML
     *
     * @param array $seoData
     * @return string
     */
    protected function compileSeoData(array $seoData): string
    {
        $meta = [];
        
        if (!empty($seoData['title'])) {
            $meta[] = "<title>{$seoData['title']}</title>";
        }
        
        foreach ($seoData as $name => $content) {
            if ($name !== 'title') {
                $meta[] = "<meta name=\"{$name}\" content=\"{$content}\">";
            }
        }
        
        return implode("\n", $meta);
    }
}

namespace App\Core\Template\Contracts;

interface TemplateInterface
{
    /**
     * Render a template
     *
     * @param string $template
     * @param array $data
     * @return string
     */
    public function render(string $template, array $data = []): string;

    /**
     * Compile a template
     *
     * @param string $template
     * @return string
     */
    public function compile(string $template): string;

    /**
     * Register a component
     *
     * @param string $name
     * @param string $path
     * @return void
     */
    public function registerComponent(string $name, string $path): void;
}

namespace App\Core\Template\Parsers;

class TemplateParser
{
    protected array $customDirectives = [];

    /**
     * Parse template content
     *
     * @param string $template
     * @return string
     */
    public function parse(string $template): string
    {
        $parsed = $this->parseCustomDirectives($template);
        $parsed = $this->parseIncludes($parsed);
        $parsed = $this->parseVariables($parsed);
        
        return $parsed;
    }

    /**
     * Add a custom directive
     *
     * @param string $name
     * @param callable $handler
     * @return void
     */
    public function directive(string $name, callable $handler): void
    {
        $this->customDirectives[$name] = $handler;
    }

    /**
     * Parse custom directives
     *
     * @param string $template
     * @return string
     */
    protected function parseCustomDirectives(string $template): string
    {
        foreach ($this->customDirectives as $name => $handler) {
            $pattern = "/@{$name}\((.*?)\)/";
            $template = preg_replace_callback($pattern, function ($matches) use ($handler) {
                return $handler($matches[1] ?? null);
            }, $template);
        }
        
        return $template;
    }

    /**
     * Parse include statements
     *
     * @param string $template
     * @return string
     */
    protected function parseIncludes(string $template): string
    {
        return preg_replace_callback(
            '/@include\((.*?)\)/',
            function ($matches) {
                $file = trim($matches[1], '\'" ');
                return $this->loadInclude($file);
            },
            $template
        );
    }

    /**
     * Parse variables
     *
     * @param string $template
     * @return string
     */
    protected function parseVariables(string $template): string
    {
        return preg_replace(
            '/\{\{\s*(.*?)\s*\}\}/',
            '<?php echo e($1); ?>',
            $template
        );
    }

    /**
     * Load included template
     *
     * @param string $file
     * @return string
     */
    protected function loadInclude(string $file): string
    {
        $path = resource_path("views/{$file}.blade.php");
        return file_exists($path) ? file_get_contents($path) : '';
    }
}
