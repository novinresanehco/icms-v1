<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View, File};

class TemplateEngine 
{
    private CacheManager $cache;
    private SecurityManager $security;
    private array $variables = [];

    public function render(string $template, array $data = []): string 
    {
        return $this->security->executeCriticalOperation(fn() => 
            $this->cache->remember("template:$template:" . md5(serialize($data)), 3600, fn() =>
                $this->compile($template, $data)
            )
        );
    }

    private function compile(string $template, array $data): string 
    {
        $this->variables = $data;
        $path = $this->resolvePath($template);
        $content = File::get($path);
        
        $compiled = $this->compileIncludes($content);
        $compiled = $this->compileVariables($compiled);
        $compiled = $this->compileDirectives($compiled);
        
        return View::make('template::base', ['content' => $compiled])->render();
    }

    private function compileIncludes(string $content): string 
    {
        return preg_replace_callback('/@include\([\'"](.*?)[\'"]\)/', function($matches) {
            return $this->compile($matches[1], $this->variables);
        }, $content);
    }

    private function compileVariables(string $content): string 
    {
        return preg_replace_callback('/\{\{ *(.*?) *\}\}/', function($matches) {
            return $this->security->sanitizeOutput(
                $this->evaluateVariable($matches[1])
            );
        }, $content);
    }

    private function compileDirectives(string $content): string 
    {
        $content = $this->compileForeach($content);
        $content = $this->compileIf($content);
        return $content;
    }

    private function evaluateVariable(string $expression): string 
    {
        $segments = explode('.', $expression);
        $value = $this->variables;
        
        foreach ($segments as $segment) {
            if (!isset($value[$segment])) {
                return '';
            }
            $value = $value[$segment];
        }
        
        return (string) $value;
    }
}

class ThemeManager 
{
    private string $activeTheme;
    private array $themes = [];
    private FileSystem $files;

    public function __construct(FileSystem $files) 
    {
        $this->files = $files;
        $this->loadThemes();
    }

    public function setActive(string $theme): void 
    {
        if (!isset($this->themes[$theme])) {
            throw new ThemeException("Theme '$theme' not found");
        }
        $this->activeTheme = $theme;
    }

    public function getPath(string $template): string 
    {
        $path = "themes/{$this->activeTheme}/templates/$template";
        
        if (!$this->files->exists($path)) {
            throw new TemplateException("Template '$template' not found");
        }
        
        return $path;
    }

    private function loadThemes(): void 
    {
        $themeDirs = $this->files->directories('themes');
        
        foreach ($themeDirs as $dir) {
            $config = include "$dir/theme.php";
            $name = basename($dir);
            $this->themes[$name] = new Theme($name, $config);
        }
    }
}

class ContentRenderer 
{
    private TemplateEngine $engine;
    private SecurityManager $security;
    private array $middleware = [];

    public function render(Content $content): string 
    {
        return $this->security->executeCriticalOperation(function() use ($content) {
            $data = $this->prepareData($content);
            $template = $content->template ?? 'default';
            
            foreach ($this->middleware as $middleware) {
                $data = $middleware->process($data);
            }
            
            return $this->engine->render($template, $data);
        });
    }

    private function prepareData(Content $content): array 
    {
        return [
            'content' => $content->toArray(),
            'meta' => $content->meta_data,
            'categories' => $content->categories,
            'media' => $content->media,
            'author' => $content->author
        ];
    }
}

class CacheManager 
{
    private array $tags = ['templates'];
    private int $defaultTtl = 3600;

    public function remember(string $key, callable $callback): mixed 
    {
        return Cache::tags($this->tags)->remember(
            $key, 
            $this->defaultTtl, 
            $callback
        );
    }

    public function forget(string $key): void 
    {
        Cache::tags($this->tags)->forget($key);
    }

    public function flush(): void 
    {
        Cache::tags($this->tags)->flush();
    }
}

class SecurityManager 
{
    private array $allowedTags = [
        'p', 'br', 'b', 'i', 'em', 'strong', 'a', 'ul', 'ol', 'li'
    ];

    public function sanitizeOutput(string $content): string 
    {
        return strip_tags($content, $this->allowedTags);
    }

    public function executeCriticalOperation(callable $operation): mixed 
    {
        try {
            return $operation();
        } catch (\Exception $e) {
            $this->handleError($e);
            throw $e;
        }
    }

    private function handleError(\Exception $e): void 
    {
        Log::error('Template error', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
}
