<?php

namespace App\Core\Template\Events;

class TemplateEventManager
{
    private SecurityManager $security;
    private array $handlers = [];
    private array $allowedEvents = [
        'template.beforeRender',
        'template.afterRender',
        'component.beforeRender',
        'component.afterRender',
        'media.beforeProcess',
        'media.afterProcess',
        'content.beforeDisplay',
        'content.afterDisplay'
    ];

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
    }

    public function dispatch(string $event, array $data): void
    {
        DB::transaction(function() use ($event, $data) {
            if (!in_array($event, $this->allowedEvents)) {
                throw new EventNotAllowedException($event);
            }

            $this->security->validateEventDispatch($event, $data);

            if (isset($this->handlers[$event])) {
                foreach ($this->handlers[$event] as $handler) {
                    $handler($data);
                }
            }
        });
    }

    public function listen(string $event, callable $handler): void
    {
        if (!in_array($event, $this->allowedEvents)) {
            throw new EventNotAllowedException($event);
        }

        if (!isset($this->handlers[$event])) {
            $this->handlers[$event] = [];
        }

        $this->handlers[$event][] = $handler;
    }
}

class TemplateLifecycleManager
{
    private TemplateEventManager $events;
    private SecurityManager $security;

    public function manageRenderCycle(string $template, array $data): string
    {
        $this->events->dispatch('template.beforeRender', [
            'template' => $template,
            'data' => $data
        ]);

        try {
            $result = $this->render($template, $data);

            $this->events->dispatch('template.afterRender', [
                'template' => $template,
                'result' => $result
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->handleRenderError($e, $template);
            throw $e;
        }
    }

    public function manageComponentCycle(string $component, array $props): string
    {
        $this->events->dispatch('component.beforeRender', [
            'component' => $component,
            'props' => $props
        ]);

        try {
            $result = $this->renderComponent($component, $props);

            $this->events->dispatch('component.afterRender', [
                'component' => $component,
                'result' => $result
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->handleComponentError($e, $component);
            throw $e;
        }
    }

    public function manageMediaCycle(array $media, array $options): string
    {
        $this->events->dispatch('media.beforeProcess', [
            'media' => $media,
            'options' => $options
        ]);

        try {
            $result = $this->processMedia($media, $options);

            $this->events->dispatch('media.afterProcess', [
                'media' => $media,
                'result' => $result
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->handleMediaError($e, $media);
            throw $e;
        }
    }

    private function handleRenderError(\Throwable $e, string $template): void
    {
        $this->security->logRenderError($e, $template);
    }

    private function handleComponentError(\Throwable $e, string $component): void
    {
        $this->security->logComponentError($e, $component);
    }

    private function handleMediaError(\Throwable $e, array $media): void
    {
        $this->security->logMediaError($e, $media);
    }
}

class EventNotAllowedException extends \Exception 
{
    public function __construct(string $event)
    {
        parent::__construct("Event not allowed: {$event}");
    }
}
