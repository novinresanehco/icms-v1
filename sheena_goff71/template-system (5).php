<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{View, Cache};
use App\Core\Security\SecurityManager;

class TemplateManager
{
    private SecurityManager $security;
    private TemplateRepository $repository;
    private LayoutManager $layout;
    private ThemeManager $theme;

    public function __construct(
        SecurityManager $security,
        TemplateRepository $repository,
        LayoutManager $layout,
        ThemeManager $theme
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->layout = $layout;
        $this->theme = $theme;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeCriticalOperation(fn() => 
            $this->renderTemplate($template, $data),
            ['action' => 'render_template', 'template' => $template]
        );
    }

    private function renderTemplate(string $template, array $data): string
    {
        $layout = $this->layout->getActive();
        $theme = $this->theme->getActive();
        
        return Cache::remember(
            "template.{$template}.{$layout}.{$theme}",
            config('cache.ttl'),
            fn() => View::make("themes.{$theme}.{$template}", array_merge(
                $data,
                ['layout' => $layout]
            ))->render()
        );
    }
}

class LayoutManager
{
    private string $activeLayout = 'default';
    private array $layouts = [];

    public function register(string $name, array $config): void
    {
        $this->layouts[$name] = $config;
    }

    public function setActive(string $layout): void
    {
        if (!isset($this->layouts[$layout])) {
            throw new \InvalidArgumentException('Invalid layout specified');
        }
        $this->activeLayout = $layout;
    }

    public function getActive(): string
    {
        return $this->activeLayout;
    }

    public function getConfig(string $layout): array
    {
        return $this->layouts[$layout] ?? [];
    }
}

class ThemeManager
{
    private string $activeTheme = 'default';
    private array $themes = [];

    public function register(string $name, array $config): void
    {
        $this->themes[$name] = $config;
    }

    public function setActive(string $theme): void
    {
        if (!isset($this->themes[$theme])) {
            throw new \InvalidArgumentException('Invalid theme specified');
        }
        $this->activeTheme = $theme;
    }

    public function getActive(): string
    {
        return $this->activeTheme;
    }

    public function getConfig(string $theme): array
    {
        return $this->themes[$theme] ?? [];
    }
}

class ComponentManager
{
    private array $components = [];

    public function register(string $name, string $class): void
    {
        $this->components[$name] = $class;
    }

    public function render(string $name, array $data = []): string
    {
        if (!isset($this->components[$name])) {
            throw new \InvalidArgumentException("Component {$name} not found");
        }

        $component = new $this->components[$name]();
        return $component->render($data);
    }
}

class BaseComponent
{
    protected string $view;
    protected array $defaultData = [];

    public function render(array $data = []): string
    {
        return View::make($this->view, array_merge(
            $this->defaultData,
            $data
        ))->render();
    }
}

class TemplateRepository
{
    private array $templates = [];

    public function store(string $name, string $content): void
    {
        $this->templates[$name] = $content;
    }

    public function get(string $name): ?string
    {
        return $this->templates[$name] ?? null;
    }

    public function exists(string $name): bool
    {
        return isset($this->templates[$name]);
    }

    public function delete(string $name): void
    {
        unset($this->templates[$name]);
    }
}

interface ThemeInterface
{
    public function getLayouts(): array;
    public function getAssets(): array;
    public function getTemplates(): array;
}
