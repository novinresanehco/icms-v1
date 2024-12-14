<?php

namespace App\Core\Plugin;

use App\Core\Plugin\Contracts\PluginEventInterface;
use App\Core\Plugin\Exceptions\EventException;
use Illuminate\Support\Collection;

class EventManager
{
    private Collection $listeners;

    public function __construct()
    {
        $this->listeners = new Collection();
    }

    /**
     * Register an event listener
     *
     * @param string $eventName
     * @param callable $listener
     * @param int $priority
     */
    public function listen(string $eventName, callable $listener, int $priority = 0): void
    {
        if (!$this->listeners->has($eventName)) {
            $this->listeners->put($eventName, new Collection());
        }

        $this->listeners->get($eventName)->push([
            'callback' => $listener,
            'priority' => $priority
        ]);

        // Sort listeners by priority
        $this->listeners->get($eventName)->sortBy('priority');
    }

    /**
     * Dispatch an event
     *
     * @param PluginEventInterface $event
     * @return array Results from all listeners
     * @throws EventException
     */
    public function dispatch(PluginEventInterface $event): array
    {
        $eventName = $event->getName();
        
        if (!$this->listeners->has($eventName)) {
            return [];
        }

        $results = [];

        foreach ($this->listeners->get($eventName) as $listener) {
            try {
                $results[] = call_user_func(
                    $listener['callback'],
                    $event->getData()
                );
            } catch (\Exception $e) {
                throw new EventException(
                    "Failed to dispatch event {$eventName}: {$e->getMessage()}"
                );
            }
        }

        return $results;
    }

    /**
     * Remove all listeners for an event
     *
     * @param string $eventName
     */
    public function forget(string $eventName): void
    {
        $this->listeners->forget($eventName);
    }

    /**
     * Get all registered listeners
     *
     * @return Collection
     */
    public function getListeners(): Collection
    {
        return $this->listeners;
    }

    /**
     * Check if an event has listeners
     *
     * @param string $eventName
     * @return bool
     */
    public function hasListeners(string $eventName): bool
    {
        return $this->listeners->has($eventName) && 
               $this->listeners->get($eventName)->isNotEmpty();
    }

    /**
     * Get number of listeners for an event
     *
     * @param string $eventName
     * @return int
     */
    public function listenerCount(string $eventName): int
    {
        if (!$this->listeners->has($eventName)) {
            return 0;
        }

        return $this->listeners->get($eventName)->count();
    }
}
