<?php

namespace App\Core\Template\Layout;

use App\Core\Template\Exceptions\LayoutException;
use Illuminate\Support\Collection;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFacade;

class LayoutManager
{
    private Collection $layouts;
    private Collection $sections;
    private ?string $currentLayout = null;
    
    public function __construct()
    {
        $this->layouts = new Collection();
        $this->sections = new Collection();
    }

    /**
     * Register a new layout
     *
     * @param string $name
     * @param string $path
     * @param array $config
     * @return void
     * @throws LayoutException
     */
    public function registerLayout(string $name, string $path, array $config = []): void
    {
        if (!file_exists($path)) {
            throw new LayoutException("Layout file not found: {$path}");
        }

        $this->layouts->put($name, [
            'path' => $path,
            'config' => array_merge($this->getDefaultConfig(), $config)
        ]);
    }

    /**
     * Set the current layout
     *
     * @param string $name
     * @return void
     * @throws LayoutException
     */
    public function setLayout(string $name): void
    {
        if (!$this->layouts->has($name)) {
            throw new LayoutException("Layout not found: {$name}");
        }

        $this->currentLayout = $name;
    }

    /**
     * Render a section within the layout
     *
     * @param string $name
     * @param mixed $content
     * @return void
     */
    public function section(string $name, $content): void
    {
        $this->sections->put($name, $content);
    }

    /**
     * Render the current layout
     *
     * @param array $data
     * @return View
     * @throws LayoutException
     */
    public function render(array $data = []): View
    {
        if (!$this->currentLayout) {
            throw new LayoutException("No layout selected");
        }

        $layout = $this->layouts->get($this->currentLayout);
        $sections = $this->sections->all();

        return ViewFacade::file($layout['path'], array_merge($data, [
            'sections' => $sections,
            'config' => $layout['config']
        ]));
    }

    /**
     * Get default layout configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'cache' => true,
            'scripts' => [],
            'styles' => [],
            'meta' => []
        ];
    }
}

class Section
{
    private string $name;
    private $content;
    private array $attributes;

    /**
     * Create a new section
     *
     * @param string $name
     * @param mixed $content
     * @param array $attributes
     */
    public function __construct(string $name, $content, array $attributes = [])
    {
        $this->name = $name;
        $this->content = $content;
        $this->attributes = $attributes;
    }

    /**
     * Render the section
     *
     * @return string
     */
    public function render(): string
    {
        $attributes = $this->renderAttributes();
        return "<div{$attributes}>{$this->content}</div>";
    }

    /**
     * Render HTML attributes
     *
     * @return string
     */
    protected function renderAttributes(): string
    {
        $attributes = array_merge([
            'class' => "section section-{$this->name}",
            'data-section' => $this->name
        ], $this->attributes);

        return collect($attributes)
            ->map(fn($value, $key) => "{$key}=\"{$value}\"")
            ->implode(' ');
    }
}

class LayoutBuilder
{
    private LayoutManager $manager;
    private array $sections = [];
    private array $assets = [];

    public function __construct(LayoutManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * Add a section to the layout
     *
     * @param string $name
     * @param mixed $content
     * @param array $attributes
     * @return self
     */
    public function addSection(string $name, $content, array $attributes = []): self
    {
        $this->sections[$name] = new Section($name, $content, $attributes);
        return $this;
    }

    /**
     * Add a script to the layout
     *
     * @param string $path
     * @param array $attributes
     * @return self
     */
    public function addScript(string $path, array $attributes = []): self
    {
        $this->assets['scripts'][] = [
            'path' => $path,
            'attributes' => $attributes
        ];
        return $this;
    }

    /**
     * Add a stylesheet to the layout
     *
     * @param string $path
     * @param array $attributes
     * @return self
     */
    public function addStyle(string $path, array $attributes = []): self
    {
        $this->assets['styles'][] = [
            'path' => $path,
            'attributes' => $attributes
        ];
        return $this;
    }

    /**
     * Build and return the layout
     *
     * @param string $name
     * @param array $data
     * @return View
     */
    public function build(string $name, array $data = []): View
    {
        $this->manager->setLayout($name);

        foreach ($this->sections as $name => $section) {
            $this->manager->section($name, $section->render());
        }

        return $this->manager->render(array_merge($data, [
            'assets' => $this->assets
        ]));
    }
}

// Example Layout Implementation
namespace App\Core\Template\Layout\Layouts;

class AdminLayout
{
    private LayoutBuilder $builder;

    public function __construct(LayoutBuilder $builder)
    {
        $this->builder = $builder;
    }

    /**
     * Build the admin layout
     *
     * @param array $data
     * @return View
     */
    public function build(array $data = []): View
    {
        return $this->builder
            ->addSection('header', view('admin.header'))
            ->addSection('sidebar', view('admin.sidebar'), [
                'class' => 'admin-sidebar collapse-sm'
            ])
            ->addSection('content', $data['content'] ?? '')
            ->addSection('footer', view('admin.footer'))
            ->addScript('/js/admin.js', ['defer' => true])
            ->addStyle('/css/admin.css')
            ->build('admin', $data);
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Layout\LayoutManager;
use App\Core\Template\Layout\Layouts\AdminLayout;

class LayoutServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(LayoutManager::class);
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        $manager = $this->app->make(LayoutManager::class);

        // Register default layouts
        $manager->registerLayout('admin', resource_path('views/layouts/admin.blade.php'), [
            'cache' => true,
            'title' => 'Admin Dashboard',
            'meta' => [
                'viewport' => 'width=device-width, initial-scale=1',
                'description' => 'ICMS Admin Dashboard'
            ]
        ]);

        // Register other layouts...
    }
}
