// app/Core/Events/EventDispatcher.php
<?php

namespace App\Core\Events;

use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Events\Dispatcher;

class EventDispatcher
{
    private array $listeners = [];
    private array $wildcardListeners = [];

    public function addListener(string $event, callable $listener, int $priority = 0): void 
    {
        $this->listeners[$event][$priority][] = $listener;
        ksort($this->listeners[$event]);
    }

    public function addWildcardListener(string $pattern, callable $listener): void 
    {
        $this->wildcardListeners[$pattern][] = $listener;
    }

    public function dispatch(object $event): void 
    {
        $eventName = get_class($event);

        try {
            $this->dispatchToListeners($event, $eventName);
            $this->dispatchToWildcardListeners($event, $eventName);
        } catch (\Throwable $e) {
            Log::error("Event dispatch error: " . $e->getMessage(), [
                'event' => $eventName,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    private function dispatchToListeners(object $event, string $eventName): void 
    {
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $priority => $listeners) {
            foreach ($listeners as $listener) {
                $listener($event);
            }
        }
    }

    private function dispatchToWildcardListeners(object $event, string $eventName): void 
    {
        foreach ($this->wildcardListeners as $pattern => $listeners) {
            if (fnmatch($pattern, $eventName)) {
                foreach ($listeners as $listener) {
                    $listener($event);
                }
            }
        }
    }
}

// app/Core/Events/EventSubscriber.php
<?php

namespace App\Core\Events;

abstract class EventSubscriber
{
    abstract public function getSubscribedEvents(): array;

    public function subscribe(EventDispatcher $dispatcher): void
    {
        foreach ($this->getSubscribedEvents() as $eventName => $params) {
            if (is_string($params)) {
                $dispatcher->addListener($eventName, [$this, $params]);
            } elseif (is_array($params)) {
                $method = $params[0];
                $priority = $params[1] ?? 0;
                $dispatcher->addListener($eventName, [$this, $method], $priority);
            }
        }
    }
}

// app/Core/Events/AsyncEventDispatcher.php
<?php

namespace App\Core\Events;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Facades\Log;

class AsyncEventDispatcher extends EventDispatcher
{
    public function __construct(private Queue $queue) 
    {
        parent::__construct();
    }

    public function dispatchAsync(object $event): void 
    {
        try {
            $this->queue->push(new HandleAsyncEvent($event));
        } catch (\Throwable $e) {
            Log::error("Async event dispatch error: " . $e->getMessage(), [
                'event' => get_class($event),
                'exception' => $e
            ]);
            throw $e;
        }
    }
}

// app/Core/Events/Jobs/HandleAsyncEvent.php
<?php

namespace App\Core\Events\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class HandleAsyncEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private object $event) {}

    public function handle(EventDispatcher $dispatcher): void 
    {
        $dispatcher->dispatch($this->event);
    }
}

// app/Core/Events/EventServiceProvider.php
<?php

namespace App\Core\Events;

use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EventDispatcher::class, function ($app) {
            return new EventDispatcher();
        });

        $this->app->singleton(AsyncEventDispatcher::class, function ($app) {
            return new AsyncEventDispatcher($app['queue']);
        });
    }

    public function boot(): void
    {
        $dispatcher = $this->app->make(EventDispatcher::class);

        foreach ($this->app->tagged('event.subscribers') as $subscriber) {
            $subscriber->subscribe($dispatcher);
        }
    }
}