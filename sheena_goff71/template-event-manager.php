<?php

namespace App\Core\Template\Events;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use App\Core\Template\Exceptions\EventException;

class EventManager
{
    private Collection $listeners;
    private Collection $subscribers;
    private EventDispatcher $dispatcher;
    private EventLogger $logger;
    private array $config;

    public function __construct(
        EventDispatcher $dispatcher,
        EventLogger $logger,
        array $config = []
    ) {
        $this->listeners = new Collection();
        $this->subscribers = new Collection();
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Add event listener
     *
     * @param string $event
     * @param callable $listener
     * @param int $priority
     * @return void
     */
    public function listen(string $event, callable $listener, int $priority = 0): void
    {
        if (!$this->listeners->has($event)) {
            $this->listeners[$event] = new Collection();
        }

        $this->listeners[$event]->push([
            'callback' => $listener,
            'priority' => $priority
        ]);

        // Sort listeners by priority
        $this->listeners[$event] = $this->listeners[$event]->sortByDesc('priority');
    }

    /**
     * Add event subscriber
     *
     * @param EventSubscriber $subscriber
     * @return void
     */
    public function subscribe(EventSubscriber $subscriber): void
    {
        $this->subscribers->push($subscriber);

        foreach ($subscriber->getSubscribedEvents() as $event => $params) {
            if (is_string($params)) {
                $this->listen($event, [$subscriber, $params]);
            } elseif (is_array($params)) {
                foreach ($params as $listener) {
                    $priority = $listener[1] ?? 0;
                    $this->listen($event, [$subscriber, $listener[0]], $priority);
                }
            }
        }
    }

    /**
     * Dispatch an event
     *
     * @param string $event
     * @param mixed $payload
     * @return mixed
     */
    public function dispatch(string $event, $payload = null)
    {
        try {
            $this->logger->logEvent($event, $payload);

            if (!$this->listeners->has($event)) {
                return $payload;
            }

            return $this->dispatcher->dispatch(
                $event,
                $this->listeners[$event],
                $payload
            );
        } catch (\Exception $e) {
            $this->handleDispatchError($e, $event, $payload);
            throw $e;
        }
    }

    /**
     * Check if event has listeners
     *
     * @param string $event
     * @return bool
     */
    public function hasListeners(string $event): bool
    {
        return $this->listeners->has($event) && $this->listeners[$event]->isNotEmpty();
    }

    /**
     * Remove event listeners
     *
     * @param string $event
     * @return void
     */
    public function removeListeners(string $event): void
    {
        $this->listeners->forget($event);
    }

    /**
     * Handle dispatch error
     *
     * @param \Exception $e
     * @param string $event
     * @param mixed $payload
     * @return void
     */
    protected function handleDispatchError(\Exception $e, string $event, $payload): void
    {
        $this->logger->logError($e, [
            'event' => $event,
            'payload' => $payload
        ]);

        if ($this->config['throw_errors']) {
            throw new EventException(
                "Error dispatching event {$event}: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Get default configuration
     *
     * @return array
     */
    protected function getDefaultConfig(): array
    {
        return [
            'throw_errors' => false,
            'log_events' => true,
            'async_events' => false
        ];
    }
}

class EventDispatcher
{
    /**
     * Dispatch event to listeners
     *
     * @param string $event
     * @param Collection $listeners
     * @param mixed $payload
     * @return mixed
     */
    public function dispatch(string $event, Collection $listeners, $payload)
    {
        foreach ($listeners as $listener) {
            $result = call_user_func($listener['callback'], $payload, $event);
            
            if ($result !== null) {
                $payload = $result;
            }
        }

        return $payload;
    }
}

abstract class EventSubscriber
{
    /**
     * Get subscribed events
     *
     * @return array
     */
    abstract public function getSubscribedEvents(): array;
}

class TemplateEventSubscriber extends EventSubscriber
{
    /**
     * Get subscribed events
     *
     * @return array
     */
    public function getSubscribedEvents(): array
    {
        return [
            'template.rendering' => [
                ['onTemplateRendering', 10]
            ],
            'template.rendered' => [
                ['onTemplateRendered', 0]
            ],
            'template.error' => [
                ['onTemplateError', 100]
            ]
        ];
    }

    /**
     * Handle template rendering event
     *
     * @param mixed $payload
     * @return void
     */
    public function onTemplateRendering($payload): void
    {
        // Pre-rendering logic
    }

    /**
     * Handle template rendered event
     *
     * @param mixed $payload
     * @return void
     */
    public function onTemplateRendered($payload): void
    {
        // Post-rendering logic
    }

    /**
     * Handle template error event
     *
     * @param mixed $payload
     * @return void
     */
    public function onTemplateError($payload): void
    {
        // Error handling logic
    }
}

class EventLogger
{
    /**
     * Log event
     *
     * @param string $event
     * @param mixed $payload
     * @return void
     */
    public function logEvent(string $event, $payload): void
    {
        Log::debug("Event dispatched: {$event}", [
            'payload' => $this->sanitizePayload($payload)
        ]);
    }

    /**
     * Log error
     *
     * @param \Exception $e
     * @param array $context
     * @return void
     */
    public function logError(\Exception $e, array $context = []): void
    {
        Log::error("Event error: {$e->getMessage()}", [
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);
    }

    /**
     * Sanitize payload for logging
     *
     * @param mixed $payload
     * @return mixed
     */
    protected function sanitizePayload($payload)
    {
        if (is_object($payload)) {
            return get_class($payload);
        }

        if (is_array($payload)) {
            return array_map([$this, 'sanitizePayload'], $payload);
        }

        return $payload;
    }
}

class AsyncEventDispatcher extends EventDispatcher
{
    /**
     * Dispatch event asynchronously
     *
     * @param string $event
     * @param Collection $listeners
     * @param mixed $payload
     * @return mixed
     */
    public function dispatch(string $event, Collection $listeners, $payload)
    {
        dispatch(function () use ($event, $listeners, $payload) {
            parent::dispatch($event, $listeners, $payload);
        })->afterResponse();

        return $payload;
    }
}

// Service Provider Registration
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Core\Template\Events\EventManager;
use App\Core\Template\Events\EventDispatcher;
use App\Core\Template\Events\EventLogger;
use App\Core\Template\Events\TemplateEventSubscriber;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Register services
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(EventManager::class, function ($app) {
            $config = config('template.events');
            $dispatcher = $config['async_events']
                ? new AsyncEventDispatcher()
                : new EventDispatcher();

            return new EventManager(
                $dispatcher,
                new EventLogger(),
                $config
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
        $events = $this->app->make(EventManager::class);

        // Register default subscriber
        $events->subscribe(new TemplateEventSubscriber());

        // Add Blade directive
        $this->app['blade.compiler']->directive('event', function ($expression) {
            return "<?php app(App\Core\Template\Events\EventManager::class)->dispatch($expression); ?>";
        });
    }
}
