<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{View, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\TemplateException;

class TemplateManager
{
    private SecurityManager $security;
    private LayoutRepository $layouts;
    private ComponentRegistry $components;
    private ThemeManager $themes;

    public function __construct(
        SecurityManager $security,
        LayoutRepository $layouts,
        ComponentRegistry $components,
        ThemeManager $themes
    ) {
        $this->security = $security;
        $this->layouts = $layouts;
        $this->components = $components;
        $this->themes = $themes;
    }

    public function render(string $template, array $data = []): string
    {
        $layout = $this->layouts->get($template);
        $theme = $this->themes->getActive();

        return View::make('templates.master', [
            'content' => $this->compile($layout, $data),
            'theme' => $theme,
            'components' => $this->components->getRegistered()
        ])->render();
    }

    private function compile(Layout $layout, array $data): string
    {
        return Cache::remember(
            "template.{$layout->id}." . md5(serialize($data)),
            3600,
            fn() => View::make($layout->view, $data)->render()
        );
    }
}

class LayoutRepository
{
    private Layout $model;

    public function get(string $name): Layout
    {
        $layout = Cache::remember(
            "layout.{$name}",
            3600,
            fn() => $this->model->where('name', $name)->first()
        );

        if (!$layout) {
            throw new TemplateException("Layout not found: {$name}");
        }

        return $layout;
    }
}

class ComponentRegistry
{
    private array $components = [];

    public function register(string $name, Component $component): void
    {
        $this->components[$name] = $component;
    }

    public function getRegistered(): array
    {
        return $this->components;
    }

    public function render(string $name, array $props = []): string
    {
        if (!isset($this->components[$name])) {
            throw new TemplateException("Component not found: {$name}");
        }

        return $this->components[$name]->render($props);
    }
}

abstract class Component
{
    protected array $props = [];
    protected string $view;

    abstract public function render(array $props = []): string;

    protected function validateProps(array $props): array
    {
        $validator = validator($props, $this->rules());
        
        if ($validator->fails()) {
            throw new TemplateException($validator->errors()->first());
        }

        return $props;
    }

    abstract protected function rules(): array;
}

class ThemeManager
{
    private Theme $model;
    private string $activeTheme;

    public function __construct()
    {
        $this->activeTheme = config('cms.default_theme', 'default');
    }

    public function getActive(): Theme
    {
        return Cache::remember(
            "theme.active",
            3600,
            fn() => $this->model->where('name', $this->activeTheme)->first()
        );
    }
}

class Layout extends Model
{
    protected $fillable = [
        'name',
        'view',
        'description'
    ];
}

class Theme extends Model
{
    protected $fillable = [
        'name',
        'path',
        'assets'
    ];

    protected $casts = [
        'assets' => 'array'
    ];
}

// Core Admin Components
class AdminTable extends Component
{
    protected string $view = 'components.admin.table';

    public function render(array $props = []): string
    {
        $this->props = $this->validateProps($props);
        return View::make($this->view, $this->props)->render();
    }

    protected function rules(): array
    {
        return [
            'headers' => 'required|array',
            'rows' => 'required|array',
            'actions' => 'array'
        ];
    }
}

class AdminForm extends Component
{
    protected string $view = 'components.admin.form';

    public function render(array $props = []): string
    {
        $this->props = $this->validateProps($props);
        return View::make($this->view, $this->props)->render();
    }

    protected function rules(): array
    {
        return [
            'fields' => 'required|array',
            'method' => 'required|in:POST,PUT,DELETE',
            'action' => 'required|string'
        ];
    }
}

class AdminNavigation extends Component
{
    protected string $view = 'components.admin.navigation';

    public function render(array $props = []): string
    {
        $this->props = $this->validateProps($props);
        return View::make($this->view, $this->props)->render();
    }

    protected function rules(): array
    {
        return [
            'items' => 'required|array',
            'user' => 'required|array'
        ];
    }
}
