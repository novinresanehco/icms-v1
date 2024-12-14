<?php

namespace App\Core\Template;

use App\Core\Security\CoreSecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Template\Engines\{TemplateEngine, ThemeManager};
use Illuminate\Support\Facades\{View, File};

class TemplateManager implements TemplateInterface
{
    private CoreSecurityManager $security;
    private CacheManager $cache;
    private TemplateEngine $engine;
    private ThemeManager $themes;
    private array $compiledTemplates = [];

    public function __construct(
        CoreSecurityManager $security,
        CacheManager $cache,
        TemplateEngine $engine,
        ThemeManager $themes
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->engine = $engine;
        $this->themes = $themes;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeCriticalOperation(
            new RenderTemplateOperation(
                $template,
                $data,
                $this->engine,
                $this->cache
            ),
            new SecurityContext('render', 'template')
        );
    }

    public function compile(string $template): string
    {
        if (isset($this->compiledTemplates[$template])) {
            return $this->compiledTemplates[$template];
        }

        return $this->cache->remember("template.compiled.$template", function() use ($template) {
            $compiled = $this->engine->compile($template);
            $this->compiledTemplates[$template] = $compiled;
            return $compiled;
        });
    }

    public function registerComponent(string $name, callable $callback): void
    {
        $this->engine->registerComponent($name, $callback);
    }

    public function setTheme(string $theme): void
    {
        $this->themes->activate($theme);
    }
}

class RenderTemplateOperation implements CriticalOperation
{
    private string $template;
    private array $data;
    private TemplateEngine $engine;
    private CacheManager $cache;

    public function __construct(
        string $template,
        array $data,
        TemplateEngine $engine,
        CacheManager $cache
    ) {
        $this->template = $template;
        $this->data = $data;
        $this->engine = $engine;
        $this->cache = $cache;
    }

    public function execute(): string
    {
        $cacheKey = $this->getCacheKey();

        return $this->cache->remember($cacheKey, function() {
            return $this->engine->render($this->template, $this->data);
        });
    }

    private function getCacheKey(): string
    {
        return sprintf(
            'template.render.%s.%s',
            md5($this->template),
            md5(serialize($this->data))
        );
    }

    public function getValidationRules(): array
    {
        return [
            'template' => 'required|string',
            'data' => 'array'
        ];
    }

    public function getRequiredPermissions(): array
    {
        return ['template.render'];
    }

    public function getRateLimitKey(): string
    {
        return 'template.render.' . md5($this->template);
    }
}

class TemplateEngine
{
    private array $components = [];
    private array $compiledComponents = [];

    public function render(string $template, array $data): string
    {
        $compiled = $this->compile($template);
        return $this->evaluate($compiled, $data);
    }

    public function compile(string $template): string
    {
        $content = File::get($this->resolveTemplatePath($template));
        
        $content = $this->compileComponents($content);
        $content = $this->compileDirectives($content);
        $content = $this->compilePHP($content);

        return $content;
    }

    public function registerComponent(string $name, callable $callback): void
    {
        $this->components[$name] = $callback;
    }

    private function compileComponents(string $content): string
    {
        foreach ($this->components as $name => $callback) {
            $pattern = "/@component\(['\"]{$name}['\"]\)(.*?)@endcomponent/s";
            $content = preg_replace_callback($pattern, function($matches) use ($name, $callback) {
                return $this->compileComponent($name, $matches[1], $callback);
            }, $content);
        }

        return $content;
    }

    private function compileComponent(string $name, string $content, callable $callback): string
    {
        if (!isset($this->compiledComponents[$name])) {
            $this->compiledComponents[$name] = $callback($content);
        }

        return $this->compiledComponents[$name];
    }

    private function compileDirectives(string $content): string
    {
        $directives = [
            'if' => '<?php if ($1): ?>',
            'else' => '<?php else: ?>',
            'endif' => '<?php endif; ?>',
            'foreach' => '<?php foreach($1): ?>',
            'endforeach' => '<?php endforeach; ?>',
            'include' => '<?php include $1; ?>'
        ];

        foreach ($directives as $directive => $replacement) {
            $pattern = "/@{$directive}(.*?)(?:\r\n|\n|$)/";
            $content = preg_replace($pattern, "$replacement\n", $content);
        }

        return $content;
    }

    private function compilePHP(string $content): string
    {
        return preg_replace('~\{{\s*(.+?)\s*\}}~is', '<?php echo $1; ?>', $content);
    }

    private function evaluate(string $compiled, array $data): string
    {
        extract($data);
        
        ob_start();
        eval('?>' . $compiled);
        return ob_get_clean();
    }

    private function resolveTemplatePath(string $template): string
    {
        $path = resource_path('views/' . str_replace('.', '/', $template) . '.blade.php');
        
        if (!File::exists($path)) {
            throw new TemplateNotFoundException("Template not found: {$template}");
        }

        return $path;
    }
}

class ThemeManager
{
    private string $activeTheme;
    private array $themes = [];

    public function register(string $name, array $config): void
    {
        $this->themes[$name] = $config;
    }

    public function activate(string $theme): void
    {
        if (!isset($this->themes[$theme])) {
            throw new ThemeNotFoundException("Theme not found: {$theme}");
        }

        $this->activeTheme = $theme;
    }

    public function getActive(): string
    {
        return $this->activeTheme;
    }

    public function getConfig(string $key = null): mixed
    {
        $config = $this->themes[$this->activeTheme] ?? null;

        if ($key === null) {
            return $config;
        }

        return $config[$key] ?? null;
    }
}
