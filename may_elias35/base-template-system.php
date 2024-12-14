<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\{View, File};

class TemplateManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private Factory $view;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        Factory $view,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->view = $view;
        $this->config = $config;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeSecureOperation(
            fn() => $this->renderTemplate($template, $data),
            ['action' => 'render_template', 'template' => $template]
        );
    }

    private function renderTemplate(string $template, array $data): string
    {
        $cacheKey = "template:{$template}:" . md5(serialize($data));
        
        return $this->cache->remember($cacheKey, 3600, function() use ($template, $data) {
            $this->validateTemplate($template);
            return $this->view->make("templates.$template", $data)->render();
        });
    }

    private function validateTemplate(string $template): void
    {
        $path = $this->config['template_path'] . "/$template.blade.php";
        if (!File::exists($path)) {
            throw new TemplateException("Template not found: $template");
        }
    }
}

class ThemeManager
{
    private string $activeTheme;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->activeTheme = $config['default_theme'];
    }

    public function setTheme(string $theme): void
    {
        if (!$this->themeExists($theme)) {
            throw new ThemeException("Theme not found: $theme");
        }
        
        $this->activeTheme = $theme;
    }

    public function getAssets(): array
    {
        $path = $this->config['themes_path'] . "/{$this->activeTheme}/assets.php";
        return File::exists($path) ? require $path : [];
    }

    private function themeExists(string $theme): bool
    {
        return File::exists($this->config['themes_path'] . "/$theme");
    }
}

class ComponentRegistry
{
    private array $components = [];
    private SecurityManager $security;
    private CacheManager $cache;

    public function __construct(SecurityManager $security, CacheManager $cache)
    {
        $this->security = $security;
        $this->cache = $cache;
    }

    public function register(string $name, ComponentInterface $component): void
    {
        $this->components[$name] = $component;
    }

    public function render(string $name, array $props = []): string
    {
        if (!isset($this->components[$name])) {
            throw new ComponentException("Component not found: $name");
        }

        return $this->security->executeSecureOperation(
            fn() => $this->renderComponent($name, $props),
            ['action' => 'render_component', 'component' => $name]
        );
    }

    private function renderComponent(string $name, array $props): string
    {
        $cacheKey = "component:{$name}:" . md5(serialize($props));
        
        return $this->cache->remember($cacheKey, 3600, function() use ($name, $props) {
            return $this->components[$name]->render($props);
        });
    }
}

interface ComponentInterface
{
    public function render(array $props = []): string;
}

class BaseComponent implements ComponentInterface
{
    protected Factory $view;
    protected array $config;

    public function __construct(Factory $view, array $config)
    {
        $this->view = $view;
        $this->config = $config;
    }

    public function render(array $props = []): string
    {
        return $this->view->make($this->getViewName(), $props)->render();
    }

    protected function getViewName(): string
    {
        return 'components.' . class_basename($this);
    }
}
