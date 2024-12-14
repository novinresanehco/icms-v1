<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Validation\ValidationService;

class TemplateManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private TemplateRepository $repository;

    public function render(string $template, array $data = []): string
    {
        return $this->cache->remember(
            $this->getCacheKey($template, $data),
            fn() => $this->compileAndRender($template, $data)
        );
    }

    private function compileAndRender(string $template, array $data): string
    {
        $compiled = $this->compile($template);
        return $this->evaluate($compiled, $data);
    }

    private function compile(string $template): string
    {
        $template = $this->compileIncludes($template);
        $template = $this->compileEscapes($template);
        $template = $this->compileEchos($template);
        return $this->compilePhp($template);
    }

    private function evaluate(string $compiled, array $data): string
    {
        $this->validator->validateTemplateData($data);
        
        ob_start();
        extract($data);
        eval('?>' . $compiled);
        return ob_get_clean();
    }
}

class TemplateRepository
{
    private const BASE_PATH = 'templates';
    
    public function get(string $name): string
    {
        $path = $this->resolvePath($name);
        return file_get_contents($path);
    }

    public function store(string $name, string $content): void
    {
        $path = $this->resolvePath($name);
        file_put_contents($path, $content);
    }

    public function exists(string $name): bool
    {
        return file_exists($this->resolvePath($name));
    }

    private function resolvePath(string $name): string
    {
        return storage_path(self::BASE_PATH . '/' . $name);
    }
}

class TemplateCompiler
{
    public function compileIncludes(string $template): string
    {
        $pattern = '/\@include\(\'(.*?)\'\)/';
        return preg_replace_callback($pattern, function($matches) {
            return $this->includeTemplate($matches[1]);
        }, $template);
    }

    public function compileEscapes(string $template): string
    {
        return preg_replace('/\{\{(.+?)\}\}/', '<?php echo htmlspecialchars($1); ?>', $template);
    }

    public function compileEchos(string $template): string
    {
        return preg_replace('/\{!!(.+?)!!\}/', '<?php echo $1; ?>', $template);
    }

    public function compilePhp(string $template): string
    {
        return preg_replace('/@php(.*?)@endphp/s', '<?php$1?>', $template);
    }

    private function includeTemplate(string $name): string
    {
        return "<?php echo \$this->render('$name'); ?>";
    }
}

class TemplateCache
{
    private CacheManager $cache;
    private const TTL = 3600;

    public function remember(string $key, callable $callback): string
    {
        return $this->cache->remember($key, $callback, self::TTL);
    }

    public function flush(): void
    {
        $this->cache->tags(['templates'])->flush();
    }
}

class ComponentManager
{
    private SecurityManager $security;
    private ComponentRepository $repository;

    public function render(string $component, array $props = []): string
    {
        $this->security->validateComponentAccess($component);
        
        $instance = $this->repository->resolve($component);
        return $instance->render($props);
    }
}

abstract class BaseComponent
{
    protected ValidationService $validator;
    
    public function render(array $props = []): string
    {
        $this->validator->validateProps($props);
        return $this->build($props);
    }

    abstract protected function build(array $props): string;
}

class AdminLayout extends BaseComponent
{
    protected function build(array $props): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html>
            <head>
                <meta charset="utf-8">
                <title>{$props['title']}</title>
            </head>
            <body>
                <nav>{$this->renderNav()}</nav>
                <main>{$props['content']}</main>
            </body>
        </html>
        HTML;
    }

    private function renderNav(): string
    {
        // Critical admin navigation only
        return '<ul><li>Dashboard</li><li>Content</li><li>Users</li></ul>';
    }
}

class ContentList extends BaseComponent
{
    protected function build(array $props): string
    {
        $items = $props['items'] ?? [];
        
        return <<<HTML
        <div class="content-list">
            {$this->renderItems($items)}
        </div>
        HTML;
    }

    private function renderItems(array $items): string
    {
        return implode('', array_map(
            fn($item) => $this->renderItem($item),
            $items
        ));
    }

    private function renderItem(array $item): string
    {
        return <<<HTML
        <div class="content-item">
            <h3>{$item['title']}</h3>
            <p>{$item['excerpt']}</p>
        </div>
        HTML;
    }
}
