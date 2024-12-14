<?php

namespace App\Core\Template\Dependencies;

use Illuminate\Support\Collection;
use App\Core\Template\Exceptions\DependencyException;

class DependencyManager
{
    private DependencyGraph $graph;
    private DependencyValidator $validator;
    private DependencyResolver $resolver;
    private DependencyCache $cache;
    private array $config;

    public function __construct(
        DependencyValidator $validator,
        DependencyResolver $resolver,
        DependencyCache $cache,
        array $config = []
    ) {
        $this->graph = new DependencyGraph();
        $this->validator = $validator;
        $this->resolver = $resolver;
        $this->cache = $cache;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Add dependency
     *
     * @param string $template
     * @param string $dependency
     * @param array $options
     * @return void
     */
    public function addDependency(string $template, string $dependency, array $options = []): void
    {
        // Validate dependency
        if (!$this->validator->validate($template, $dependency, $options)) {
            throw new DependencyException("Invalid dependency: {$template} -> {$dependency}");
        }

        // Check for circular dependencies
        if ($this->wouldCreateCycle($template, $dependency)) {
            throw new DependencyException("Circular dependency detected");
        }

        // Add to graph
        $this->graph->addEdge($template, $dependency, $options);

        // Clear cache
        $this->cache->clear($template);
    }

    /**
     * Remove dependency
     *
     * @param string $template
     * @param string $dependency
     * @return void
     */
    public function removeDependency(string $template, string $dependency): void
    {
        $this->graph->removeEdge($template, $dependency);
        $this->cache->clear($template);
    }

    /**
     * Get template dependencies
     *
     * @param string $template
     * @param bool $recursive
     * @return Collection
     */
    public function getDependencies(string $template, bool $recursive = false): Collection
    {
        $cacheKey = "dependencies:{$template}:" . ($recursive ? 'recursive' : 'direct');

        return $this->cache->remember($cacheKey, function () use ($template, $recursive) {
            return $recursive
                ? $this->graph->getRecursiveDependencies($template)
                : $this->graph->getDirectDependencies($template);
        });
    }

    /**
     * Get dependent templates
     *
     * @param string $template
     * @param bool $recursive
     * @return Collection
     */
    public function getDependents(string $template, bool $recursive = false): Collection
    {
        $cacheKey = "dependents:{$template}:" . ($recursive ? 'recursive' : 'direct');

        return $this->cache->remember($cacheKey, function () use ($template, $recursive) {
            return $recursive
                ? $this->graph->getRecursiveDependents($template)
                : $this->graph->getDirectDependents($template);
        });
    }

    /**
     * Resolve dependencies
     *
     * @param string $template
     * @return Collection
     */
    public function resolve(string $template): Collection
    {
        return $this->resolver->resolve($template, $this->graph);
    }

    /**
     * Check dependency order
     *
     * @param string $template
     * @param array $dependencies
     * @return bool
     */
    public function checkOrder(string $template, array $dependencies): bool
    {
        return $this->resolver->checkOrder($template, $dependencies, $this->graph);
    }

    /**
     * Analyze impact of changes
     *
     * @param string $template
     * @return array
     */
    public function analyzeImpact(string $template): array
    {
        $dependents = $this->getDependents($template, true);
        
        return [
            'direct_impact' => $this->graph->getDirectDependents($template)->count(),
            'indirect_impact' => $dependents->count(),
            'critical_paths' => $this->findCriticalPaths($template),
            'risk_level' => $this->calculateRiskLevel($dependents)
        ];
    }

    /**
     * Check if adding dependency would create cycle
     *
     * @param string $template
     * @param string $dependency
     * @return bool
     */
    protected function wouldCreateCycle(string $template, string $dependency): bool
    {
        if ($template === $dependency) {
            return true;
        }

        return $this->graph->getRecursiveDependents($dependency)->contains($template);
    }

    /**
     * Find critical paths in dependency graph
     *
     * @param string $template
     * @return array
     */
    protected function findCriticalPaths(string $template): array
    {
        $paths = [];
        $visited = new Collection();

        $this->findPaths($template, $visited, [], $paths);

        return $paths;
    }

    /**
     * Find all paths in graph
     *
     * @param string $current
     * @param Collection $visited
     * @param array $path
     * @param array $paths
     * @return void
     */
    protected function findPaths(string $current, Collection $visited, array $path, array &$paths): void
    {
        $visited->push($current);
        $path[] = $current;

        $dependents = $this->graph->getDirectDependents($current);

        if ($dependents->isEmpty()) {
            $paths[] = $path;
        } else {
            foreach ($dependents as $dependent) {
                if (!$visited->contains($dependent)) {
                    $this->findPaths($dependent, $visited, $path, $paths);
                }
            }
        }

        $visited->pop();
    }

    /**
     * Calculate risk level based on dependencies
     *
     * @param Collection $dependents
     * @return string
     */
    protected function calculateRiskLevel(Collection $dependents): string
    {
        $count = $dependents->count();

        if ($count > $this->config['high_risk_threshold']) {
            return 'high';
        } elseif ($count > $this->config['medium_risk_threshold']) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'enable_cache' => true,
            'cache_ttl' => 3600,
            'high_risk_threshold' => 10,
            'medium_risk_threshold' => 5,
            'max_depth' => 10
        ];
    }
}

class DependencyGraph
{
    private array $nodes = [];
    private array $edges = [];

    /**
     * Add edge to graph
     *
     * @param string $from
     * @param string $to
     * @param array $options
     * @return void
     */
    public function addEdge(string $from, string $to, array $options = []): void
    {
        if (!isset($this->nodes[$from])) {
            $this->nodes[$from] = [];
        }
        if (!isset($this->nodes[$to])) {
            $this->nodes[$to] = [];
        }

        $this->edges["{$from}->{$to}"] = $options;
        $this->nodes[$from][] = $to;
    }

    /**
     * Remove edge from graph
     *
     * @param string $from
     * @param string $to
     * @return void
     */
    public function removeEdge(string $from, string $to): void
    {
        unset($this->edges["{$from}->{$to}"]);
        
        if (isset($this->nodes[$from])) {
            $this->nodes[$from] = array_diff($this->nodes[$from], [$to]);
        }
    }

    /**
     * Get direct dependencies
     *
     * @param string $node
     * @return Collection
     */
    public function getDirectDependencies(string $node): Collection
    {
        return collect($this->nodes[$node] ?? []);
    }

    /**
     * Get recursive dependencies
     *
     * @param string $node
     * @return Collection
     */
    public function getRecursiveDependencies(string $node): Collection
    {
        $dependencies = new Collection();
        $this->collectDependencies($node, $dependencies);
        return $dependencies;
    }

    /**
     * Get direct dependents
     *
     * @param string $node
     * @return Collection
     */
    public function getDirectDependents(string $node): Collection
    {
        $dependents = [];
        
        foreach ($this->nodes as $from => $targets) {
            if (in_array($node, $targets)) {
                $dependents[] = $from;
            }
        }

        return collect($dependents);
    }

    /**
     * Get recursive dependents
     *
     * @param string $node
     * @return Collection
     */
    public function getRecursiveDependents(string $node): Collection
    {
        $dependents = new Collection();
        $this->collectDependents($node, $dependents);
        return $dependents;
    }

    /**
     * Collect dependencies recursively
     *
     * @param string $node
     * @param Collection $collected
     * @return void
     */
    private function collectDependencies(string $node, Collection $collected): void
    {
        foreach ($this->nodes[$node] ?? [] as $dependency) {
            if (!$collected->contains($dependency)) {
                $collected->push($dependency);
                $this->collectDependencies($dependency, $collected);
            }
        }
    }

    /**
     * Collect dependents recursively
     *
     * @param string $node
     * @param Collection $collected
     * @return void
     */
    private function collectDependents(string $node, Collection $collected): void
    {
        foreach ($this->getDirectDependents($node) as $dependent) {
            if (!$collected->contains($dependent)) {
                $collected->push($dependent);
                $this->collectDependents($dependent, $collected);
            }
        }
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Dependencies\DependencyManager;
use App\Core\Template\Dependencies\DependencyValidator;
use App\Core\Template\Dependencies\DependencyResolver;
use App\Core\Template\Dependencies\DependencyCache;

class DependencyServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(DependencyManager::class, function ($app) {
            return new DependencyManager(
                new DependencyValidator(),
                new DependencyResolver(),
                new DependencyCache(),
                config('template.dependencies')
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
        // Register dependency checking middleware
        $this->app['router']->pushMiddleware(
            \App\Http\Middleware\CheckTemplateDependencies::class
        );
    }
}
