<?php

namespace App\Core\Repositories;

class RepositoryEventDispatcher
{
    protected $listeners = [];

    public function addListener(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function dispatch(string $event, $payload): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            $listener($payload);
        }
    }

    public function removeListener(string $event, callable $listener): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        $this->listeners[$event] = array_filter(
            $this->listeners[$event],
            fn($existing) => $existing !== $listener
        );
    }

    public function clearListeners(string $event): void
    {
        unset($this->listeners[$event]);
    }
}
