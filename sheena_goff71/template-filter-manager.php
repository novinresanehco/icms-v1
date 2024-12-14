<?php

namespace App\Core\Template\Filters;

use Illuminate\Support\Collection;
use App\Core\Template\Exceptions\FilterException;

class FilterManager
{
    private Collection $filters;
    private Collection $chains;
    private FilterCache $cache;
    private array $config;

    public function __construct(FilterCache $cache, array $config = [])
    {
        $this->filters = new Collection();
        $this->chains = new Collection();
        $this->cache = $cache;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Register a filter
     *
     * @param string $name
     * @param callable $filter
     * @return void
     */
    public function register(string $name, callable $filter): void
    {
        $this->filters->put($name, new Filter($name, $filter));
    }

    /**
     * Apply filter to content
     *
     * @param string $name
     * @param mixed $content
     * @param array $options
     * @return mixed
     */
    public function apply(string $name, $content, array $options = [])
    {
        if (!$this->filters->has($name)) {
            throw new FilterException("Filter not found: {$name}");
        }

        $cacheKey = $this->getCacheKey($name, $content, $options);
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        $result = $this->filters->get($name)->apply($content, $options);
        $this->cache->put($cacheKey, $result);

        return $result;
    }

    /**
     * Create filter chain
     *
     * @param string $name
     * @return FilterChain
     */
    public function chain(string $name): FilterChain
    {
        $chain = new FilterChain($name, $this);
        $this->chains->put($name, $chain);
        return $chain;
    }

    /**
     * Apply filter chain
     *
     * @param string $name
     * @param mixed $content
     * @param array $options
     * @return mixed
     */
    public function applyChain(string $name, $content, array $options = [])
    {
        if (!$this->chains->has($name)) {
            throw new FilterException("Filter chain not found: {$name}");
        }

        return $this->chains->get($name)->process($content, $options);
    }

    /**
     * Generate cache key
     *
     * @param string $name
     * @param mixed $content
     * @param array $options
     * @return string
     */
    protected function getCacheKey(string $name, $content, array $options): string
    {
        return sprintf(
            'filter:%s:%s:%s',
            $name,
            md5(is_string($content) ? $content : serialize($content)),
            md5(serialize($options))
        );
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'cache_enabled' => true,
            'cache_ttl' => 3600,
            'safe_mode' => true
        ];
    }
}

class Filter
{
    private string $name;
    private $callback;

    public function __construct(string $name, callable $callback)
    {
        $this->name = $name;
        $this->callback = $callback;
    }

    /**
     * Apply filter
     *
     * @param mixed $content
     * @param array $options
     * @return mixed
     */
    public function apply($content, array $options = [])
    {
        return call_user_func($this->callback, $content, $options);
    }

    /**
     * Get filter name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }
}

class FilterChain
{
    private string $name;
    private array $filters = [];
    private FilterManager $manager;

    public function __construct(string $name, FilterManager $manager)
    {
        $this->name = $name;
        $this->manager = $manager;
    }

    /**
     * Add filter to chain
     *
     * @param string $name
     * @param array $options
     * @return self
     */
    public function add(string $name, array $options = []): self
    {
        $this->filters[] = [
            'name' => $name,
            'options' => $options
        ];
        return $this;
    }

    /**
     * Process content through filter chain
     *
     * @param mixed $content
     * @param array $options
     * @return mixed
     */
    public function process($content, array $options = [])
    {
        foreach ($this->filters as $filter) {
            $filterOptions = array_merge($filter['options'], $options);
            $content = $this->manager->apply($filter['name'], $content, $filterOptions);
        }
        return $content;
    }
}

class FilterCache
{
    private array $cache = [];
    private int $ttl;

    public function __construct(int $ttl = 3600)
    {
        $this->ttl = $ttl;
    }

    /**
     * Get cached content
     *
     * @param string $key
     * @return mixed|null
     */
    public function get(string $key)
    {
        if (!isset($this->cache[$key])) {
            return null;
        }

        [$value, $expiry] = $this->cache[$key];

        if ($expiry < time()) {
            unset($this->cache[$key]);
            return null;
        }

        return $value;
    }

    /**
     * Cache content
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function put(string $key, $value): void
    {
        $this->cache[$key] = [$value, time() + $this->ttl];
    }

    /**
     * Clear cache
     *
     * @return void
     */
    public function clear(): void
    {
        $this->cache = [];
    }
}

// Common Filter Implementations
class CommonFilters
{
    /**
     * HTML special chars filter
     *
     * @param string $content
     * @return string
     */
    public static function escape(string $content): string
    {
        return htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Strip tags filter
     *
     * @param string $content
     * @param array $options
     * @return string
     */
    public static function stripTags(string $content, array $options = []): string
    {
        $allowedTags = $options['allowed_tags'] ?? '';
        return strip_tags($content, $allowedTags);
    }

    /**
     * Markdown filter
     *
     * @param string $content
     * @return string
     */
    public static function markdown(string $content): string
    {
        return (new \Parsedown())->text($content);
    }

    /**
     * Truncate filter
     *
     * @param string $content
     * @param array $options
     * @return string
     */
    public static function truncate(string $content, array $options = []): string
    {
        $length = $options['length'] ?? 100;
        $suffix = $options['suffix'] ?? '...';
        
        if (mb_strlen($content) <= $length) {
            return $content;
        }

        return mb_substr($content, 0, $length) . $suffix;
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Filters\FilterManager;
use App\Core\Template\Filters\FilterCache;
use App\Core\Template\Filters\CommonFilters;

class FilterServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(FilterManager::class, function ($app) {
            return new FilterManager(
                new FilterCache(),
                config('template.filters')
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
        $filters = $this->app->make(FilterManager::class);

        // Register common filters
        $filters->register('escape', [CommonFilters::class, 'escape']);
        $filters->register('strip_tags', [CommonFilters::class, 'stripTags']);
        $filters->register('markdown', [CommonFilters::class, 'markdown']);
        $filters->register('truncate', [CommonFilters::class, 'truncate']);

        // Register common filter chains
        $filters->chain('content')
            ->add('strip_tags', ['allowed_tags' => '<p><a><strong><em>'])
            ->add('escape')
            ->add('markdown');

        // Add Blade directive
        $this->app['blade.compiler']->directive('filter', function ($expression) {
            return "<?php echo app(App\Core\Template\Filters\FilterManager::class)->apply($expression); ?>";
        });
    }
}
