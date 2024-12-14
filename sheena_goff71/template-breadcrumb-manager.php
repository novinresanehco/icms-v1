<?php

namespace App\Core\Template\Breadcrumbs;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use App\Core\Template\Exceptions\BreadcrumbException;

class BreadcrumbManager
{
    private Collection $trails;
    private Collection $definitions;
    private BreadcrumbRenderer $renderer;
    private array $config;
    private ?array $currentTrail = null;

    public function __construct(BreadcrumbRenderer $renderer, array $config = [])
    {
        $this->trails = new Collection();
        $this->definitions = new Collection();
        $this->renderer = $renderer;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Define a breadcrumb trail
     *
     * @param string $name
     * @param callable $callback
     * @return void
     */
    public function define(string $name, callable $callback): void
    {
        $this->definitions->put($name, $callback);
    }

    /**
     * Generate breadcrumb trail
     *
     * @param string $name
     * @param mixed ...$params
     * @return array
     */
    public function generate(string $name, ...$params): array
    {
        if (!$this->definitions->has($name)) {
            throw new BreadcrumbException("Breadcrumb trail not defined: {$name}");
        }

        $trail = new BreadcrumbTrail();
        $callback = $this->definitions->get($name);
        
        call_user_func($callback, $trail, ...$params);
        
        $this->currentTrail = $trail->getBreadcrumbs();
        $this->trails->put($name, $this->currentTrail);
        
        return $this->currentTrail;
    }

    /**
     * Render breadcrumb trail
     *
     * @param string $name
     * @param mixed ...$params
     * @return string
     */
    public function render(string $name, ...$params): string
    {
        $trail = $this->generate($name, ...$params);
        return $this->renderer->render($trail, $this->config);
    }

    /**
     * Get current breadcrumb trail
     *
     * @return array|null
     */
    public function current(): ?array
    {
        return $this->currentTrail;
    }

    /**
     * Get last breadcrumb in trail
     *
     * @return array|null
     */
    public function current_item(): ?array
    {
        return $this->currentTrail ? end($this->currentTrail) : null;
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'container_class' => 'breadcrumbs',
            'item_class' => 'breadcrumb-item',
            'active_class' => 'active',
            'divider' => '/',
            'home_icon' => '<i class="fas fa-home"></i>',
            'structured_data' => true
        ];
    }
}

class BreadcrumbTrail
{
    private array $breadcrumbs = [];

    /**
     * Add breadcrumb item
     *
     * @param string $title
     * @param string|null $url
     * @param array $data
     * @return self
     */
    public function push(string $title, ?string $url = null, array $data = []): self
    {
        $this->breadcrumbs[] = array_merge([
            'title' => $title,
            'url' => $url,
        ], $data);

        return $this;
    }

    /**
     * Add home breadcrumb
     *
     * @param string|null $url
     * @return self
     */
    public function home(?string $url = '/'): self
    {
        return $this->push('Home', $url, ['is_home' => true]);
    }

    /**
     * Get breadcrumbs array
     *
     * @return array
     */
    public function getBreadcrumbs(): array
    {
        return $this->breadcrumbs;
    }
}

class BreadcrumbRenderer
{
    /**
     * Render breadcrumb trail
     *
     * @param array $trail
     * @param array $config
     * @return string
     */
    public function render(array $trail, array $config): string
    {
        $items = $this->renderItems($trail, $config);
        $schema = $config['structured_data'] ? $this->generateSchema($trail) : '';

        return <<<HTML
        <nav aria-label="breadcrumb">
            <ol class="{$config['container_class']}">
                {$items}
            </ol>
            {$schema}
        </nav>
        HTML;
    }

    /**
     * Render breadcrumb items
     *
     * @param array $trail
     * @param array $config
     * @return string
     */
    protected function renderItems(array $trail, array $config): string
    {
        $items = [];
        $lastIndex = count($trail) - 1;

        foreach ($trail as $index => $item) {
            $isLast = $index === $lastIndex;
            $items[] = $this->renderItem($item, $isLast, $config);
        }

        return implode("\n", $items);
    }

    /**
     * Render single breadcrumb item
     *
     * @param array $item
     * @param bool $isLast
     * @param array $config
     * @return string
     */
    protected function renderItem(array $item, bool $isLast, array $config): string
    {
        $title = $item['is_home'] ?? false ? $config['home_icon'] : htmlspecialchars($item['title']);
        $classes = [$config['item_class']];
        
        if ($isLast) {
            $classes[] = $config['active_class'];
        }

        $content = $isLast || !$item['url'] 
            ? $title 
            : sprintf('<a href="%s">%s</a>', $item['url'], $title);

        if (!$isLast) {
            $content .= sprintf('<span class="divider">%s</span>', $config['divider']);
        }

        return sprintf(
            '<li class="%s"%s>%s</li>',
            implode(' ', $classes),
            $isLast ? ' aria-current="page"' : '',
            $content
        );
    }

    /**
     * Generate structured data
     *
     * @param array $trail
     * @return string
     */
    protected function generateSchema(array $trail): string
    {
        $items = [];
        $position = 1;

        foreach ($trail as $item) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'item' => [
                    '@id' => $item['url'] ?? '',
                    'name' => $item['title']
                ]
            ];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items
        ];

        return sprintf(
            '<script type="application/ld+json">%s</script>',
            json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Breadcrumbs\BreadcrumbManager;
use App\Core\Template\Breadcrumbs\BreadcrumbRenderer;

class BreadcrumbServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(BreadcrumbManager::class, function ($app) {
            return new BreadcrumbManager(
                new BreadcrumbRenderer(),
                config('breadcrumbs')
            );
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        $breadcrumbs = $this->app->make(BreadcrumbManager::class);

        // Register default home breadcrumb
        $breadcrumbs->define('home', function ($trail) {
            $trail->home();
        });

        // Add Blade directive
        $this->app['blade.compiler']->directive('breadcrumbs', function ($expression) {
            return "<?php echo app(App\Core\Template\Breadcrumbs\BreadcrumbManager::class)->render($expression); ?>";
        });
    }
}
