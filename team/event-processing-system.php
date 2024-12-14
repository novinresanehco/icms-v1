namespace App\Core\Events;

class CriticalEventProcessor implements EventProcessorInterface
{
    private EventDispatcher $dispatcher;
    private EventValidator $validator;
    private SecurityManager $security;
    private AuditLogger $audit;

    public function processEvent(CriticalEvent $event): void
    {
        DB::transaction(function() use ($event) {
            // Validate event
            $this->validator->validateEvent($event);
            
            // Security check
            $this->security->validateEventSecurity($event);
            
            // Process event
            $this->dispatcher->dispatch($event);
            
            // Audit logging
            $this->audit->logEvent($event);
        });
    }

    private function validateEventSecurity(CriticalEvent $event): void
    {
        if (!$this->security->validateEvent($event)) {
            throw new SecurityException(
                'Event security validation failed',
                ['event' => $event->getType()]
            );
        }
    }
}

class EventDispatcher implements DispatcherInterface
{
    private ListenerRegistry $listeners;
    private EventStore $store;
    private MetricsCollector $metrics;

    public function dispatch(Event $event): void
    {
        $context = $this->createContext($event);
        
        try {
            // Store event
            $this->store->storeEvent($event);
            
            // Notify listeners
            $this->notifyListeners($event);
            
            // Record metrics
            $this->recordMetrics($context);
            
        } catch (\Exception $e) {
            $this->handleDispatchFailure($event, $e);
            throw $e;
        }
    }

    private function notifyListeners(Event $event): void
    {
        foreach ($this->listeners->getListeners($event) as $listener) {
            $this->executeListener($listener, $event);
        }
    }

    private function executeListener(EventListener $listener, Event $event): void
    {
        try {
            $listener->handle($event);
        } catch (\Exception $e) {
            $this->handleListenerFailure($listener, $event, $e);
        }
    }
}
