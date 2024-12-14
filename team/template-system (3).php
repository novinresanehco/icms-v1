<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{View, Cache};

class TemplateManager
{
    private SecurityManager $security;
    private TemplateRepository $repository;
    private ComponentRegistry $components;
    private CacheManager $cache;

    public function __construct(
        SecurityManager $security,
        TemplateRepository $repository,
        ComponentRegistry $components,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->components = $components;
        $this->cache = $cache;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->renderTemplate($template, $data),
            ['action' => 'render', 'template' => $template]
        );
    }

    private function renderTemplate(string $template, array $data): string
    {
        $template = $this->repository->find($template);
        $compiledTemplate = $this->compile($template, $data);
        return $this->renderWithComponents($compiledTemplate, $data);
    }

    private function compile(Template $template, array $data): string
    {
        return $this->cache->remember(
            "template.{$template->id}",
            fn() => View::make($template->path, $data)->render()
        );
    }

    private function renderWithComponents(string $content, array $data): string
    {
        return preg_replace_callback(
            '/@component\([\'"](.*?)[\'"]\s*,?\s*(.*?)\)/',
            fn($matches) => $this->renderComponent($matches[1], eval("return [{$matches[2]}];"), $data),
            $content
        );
    }

    private function renderComponent(string $name, array $props, array $data): string
    {
        $component = $this->components->get($name);
        return $component->render(array_merge($props, $data));
    }
}

class TemplateRepository
{
    public function find(string $name): Template
    {
        return Template::where('name', $name)->firstOrFail();
    }

    public function create(array $data): Template
    {
        return DB::transaction(function() use ($data) {
            return Template::create([
                'name' => $data['name'],
                'path' => $data['path'],
                'type' => $data['type'] ?? 'blade',
                'is_active' => true
            ]);
        });
    }

    public function update(int $id, array $data): Template
    {
        return DB::transaction(function() use ($id, $data) {
            $template = Template::findOrFail($id);
            $template->update($data);
            return $template;
        });
    }
}

class ComponentRegistry
{
    private array $components = [];

    public function register(string $name, Component $component): void
    {
        $this->components[$name] = $component;
    }

    public function get(string $name): Component
    {
        if (!isset($this->components[$name])) {
            throw new ComponentException("Component {$name} not found");
        }
        return $this->components[$name];
    }
}

abstract class Component
{
    protected SecurityManager $security;

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
    }

    public function render(array $data = []): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->renderComponent($data),
            ['action' => 'render_component', 'component' => static::class]
        );
    }

    abstract protected function renderComponent(array $data): string;
}

class Template extends Model
{
    protected $fillable = [
        'name',
        'path',
        'type',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean'
    ];
}

class LayoutComponent extends Component
{
    protected function renderComponent(array $data): string
    {
        return View::make('components.layout', $data)->render();
    }
}

class NavigationComponent extends Component
{
    protected function renderComponent(array $data): string
    {
        return View::make('components.navigation', $data)->render();
    }
}

class ContentComponent extends Component
{
    protected function renderComponent(array $data): string
    {
        return View::make('components.content', $data)->render();
    }
}

class FooterComponent extends Component
{
    protected function renderComponent(array $data): string
    {
        return View::make('components.footer', $data)->render();
    }
}
