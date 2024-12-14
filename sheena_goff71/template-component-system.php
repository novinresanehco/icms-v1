<?php

namespace App\Core\Template\Components;

use App\Core\Template\Exceptions\ComponentException;
use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Illuminate\Support\Str;

abstract class BaseComponent extends Component
{
    protected array $attributes = [];
    protected array $slots = [];
    protected bool $shouldRender = true;

    /**
     * Create a new component instance
     *
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->attributes = $attributes;
        $this->validateAttributes();
    }

    /**
     * Get required attributes for the component
     *
     * @return array
     */
    abstract public function requiredAttributes(): array;

    /**
     * Validate component attributes
     *
     * @throws ComponentException
     */
    protected function validateAttributes(): void
    {
        $missing = array_diff($this->requiredAttributes(), array_keys($this->attributes));
        
        if (!empty($missing)) {
            throw new ComponentException(
                sprintf('Missing required attributes: %s', implode(', ', $missing))
            );
        }
    }
}

class ComponentRegistry
{
    private Collection $components;
    private array $aliases = [];

    public function __construct()
    {
        $this->components = new Collection();
    }

    /**
     * Register a new component
     *
     * @param string $name
     * @param string $class
     * @param string|null $alias
     * @return void
     */
    public function register(string $name, string $class, ?string $alias = null): void
    {
        $this->validateComponentClass($class);
        
        $this->components->put($name, $class);
        
        if ($alias) {
            $this->aliases[$alias] = $name;
        }
    }

    /**
     * Get a component by name
     *
     * @param string $name
     * @return string|null
     */
    public function resolve(string $name): ?string
    {
        return $this->components->get(
            $this->aliases[$name] ?? $name
        );
    }

    /**
     * Check if a component exists
     *
     * @param string $name
     * @return bool
     */
    public function exists(string $name): bool
    {
        return $this->components->has($name) || isset($this->aliases[$name]);
    }

    /**
     * Validate component class
     *
     * @param string $class
     * @throws ComponentException
     */
    protected function validateComponentClass(string $class): void
    {
        if (!class_exists($class)) {
            throw new ComponentException("Component class {$class} does not exist");
        }

        if (!is_subclass_of($class, BaseComponent::class)) {
            throw new ComponentException("Component class must extend BaseComponent");
        }
    }
}

class ComponentFactory
{
    private ComponentRegistry $registry;

    public function __construct(ComponentRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Create a new component instance
     *
     * @param string $name
     * @param array $attributes
     * @return BaseComponent
     * @throws ComponentException
     */
    public function create(string $name, array $attributes = []): BaseComponent
    {
        $class = $this->registry->resolve($name);
        
        if (!$class) {
            throw new ComponentException("Component {$name} not found");
        }

        return new $class($attributes);
    }
}

class ComponentRenderer
{
    private ComponentFactory $factory;

    public function __construct(ComponentFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Render a component
     *
     * @param string $name
     * @param array $attributes
     * @param string|null $slot
     * @return string
     * @throws ComponentException
     */
    public function render(string $name, array $attributes = [], ?string $slot = null): string
    {
        $component = $this->factory->create($name, $attributes);
        
        if ($slot !== null) {
            $component->slot($slot);
        }

        return $component->render();
    }
}

// Example Implementation of Common Components
class Card extends BaseComponent
{
    /**
     * Get required attributes
     *
     * @return array
     */
    public function requiredAttributes(): array
    {
        return ['title'];
    }

    /**
     * Render the component
     *
     * @return string
     */
    public function render(): string
    {
        return <<<HTML
        <div class="card">
            <div class="card-header">{$this->attributes['title']}</div>
            <div class="card-body">
                {$this->slots['default'] ?? ''}
            </div>
        </div>
        HTML;
    }
}

class Alert extends BaseComponent
{
    /**
     * Get required attributes
     *
     * @return array
     */
    public function requiredAttributes(): array
    {
        return ['type', 'message'];
    }

    /**
     * Render the component
     *
     * @return string
     */
    public function render(): string
    {
        $type = $this->attributes['type'];
        $message = $this->attributes['message'];
        
        return <<<HTML
        <div class="alert alert-{$type}" role="alert">
            {$message}
            {$this->slots['default'] ?? ''}
        </div>
        HTML;
    }
}

// Service Provider for Component Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Components\ComponentRegistry;
use App\Core\Template\Components\Card;
use App\Core\Template\Components\Alert;

class ComponentServiceProvider extends ServiceProvider
{
    /**
     * Register components
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(ComponentRegistry::class, function () {
            $registry = new ComponentRegistry();
            
            // Register core components
            $registry->register('card', Card::class, 'ui-card');
            $registry->register('alert', Alert::class, 'ui-alert');
            
            return $registry;
        });
    }

    /**
     * Bootstrap services
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadViewComponentsAs('ui', [
            Card::class,
            Alert::class,
        ]);
    }
}
