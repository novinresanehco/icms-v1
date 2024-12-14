<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\{View, File};

class TemplateManager
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->cache->remember("template.{$template}", function() use ($template, $data) {
            $this->security->validateAccess('template.render');
            
            $template = $this->resolveTemplate($template);
            $data = $this->prepareData($data);
            
            return View::make($template, $data)->render();
        });
    }

    public function compile(string $template): void
    {
        $this->security->validateAccess('template.compile');
        
        $path = $this->getTemplatePath($template);
        $content = File::get($path);
        
        $compiled = $this->compileTemplate($content);
        
        File::put($this->getCompiledPath($template), $compiled);
        $this->cache->invalidate(['template']);
    }

    protected function resolveTemplate(string $name): string
    {
        $path = $this->getTemplatePath($name);
        
        if (!File::exists($path)) {
            throw new TemplateNotFoundException("Template not found: {$name}");
        }
        
        return $name;
    }

    protected function prepareData(array $data): array
    {
        return array_merge([
            'security' => $this->security,
            'user' => $this->security->getCurrentUser(),
            'config' => $this->config
        ], $data);
    }

    protected function compileTemplate(string $content): string
    {
        // Basic template compilation
        $compiled = preg_replace('/\{\{(.+?)\}\}/', '<?php echo $1; ?>', $content);
        $compiled = preg_replace('/\{%(.+?)%\}/', '<?php $1; ?>', $compiled);
        
        return $compiled;
    }

    protected function getTemplatePath(string $name): string
    {
        return resource_path("views/templates/{$name}.blade.php");
    }

    protected function getCompiledPath(string $name): string
    {
        return storage_path("framework/views/{$name}.php");
    }

    public function clearCache(): void
    {
        $this->security->validateAccess('template.cache.clear');
        $this->cache->invalidate(['template']);
    }

    public function registerComponents(array $components): void
    {
        $this->security->validateAccess('template.components.register');
        
        foreach ($components as $name => $component) {
            View::component($name, $component);
        }
        
        $this->cache->invalidate(['template', 'components']);
    }

    public function extend(string $name, callable $extension): void
    {
        $this->security->validateAccess('template.extend');
        View::composer($name, $extension);
        $this->cache->invalidate(['template', $name]);
    }
}
