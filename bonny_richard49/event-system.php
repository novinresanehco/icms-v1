<?php
namespace App\Core\Events;

class EventDispatcher
{
    private array $listeners = [];
    private SecurityManager $security;
    private LogManager $logger;

    public function dispatch(string $event, array $payload = []): void
    {
        DB::beginTransaction();
        try {
            $this->security->validateEvent($event, $payload);
            $this->dispatchToListeners($event, $payload);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logEventFailure($e, $event, $payload);
            throw $e;
        }
    }

    private function dispatchToListeners(string $event, array $payload): void
    {
        foreach ($this->getListeners($event) as $listener) {
            try {
                $listener($payload);
            } catch (\Exception $e) {
                $this->handleListenerFailure($e, $event, $listener);
            }
        }
    }

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    private function handleListenerFailure(\Exception $e, string $event, callable $listener): void
    {
        $this->logger->logListenerFailure($e, $event, $listener);
        
        if ($this->isCriticalEvent($event)) {
            throw $e;
        }
    }
}

class CriticalEventSubscriber
{
    private SystemMonitor $monitor;
    private AlertManager $alerts;
    private RecoveryManager $recovery;

    public function subscribe(EventDispatcher $events): void
    {
        $events->listen(SystemEvents::CRITICAL_ERROR, [$this, 'handleCriticalError']);
        $events->listen(SystemEvents::SECURITY_BREACH, [$this, 'handleSecurityBreach']);
        $events->listen(SystemEvents::PERFORMANCE_DEGRADATION, [$this, 'handlePerformanceDrop']);
    }

    public function handleCriticalError(array $payload): void
    {
        $this->monitor->logCriticalEvent($payload);
        $this->alerts->triggerCriticalAlert($payload);
        $this->recovery->initiateEmergencyProtocol();
    }

    public function handleSecurityBreach(array $payload): void
    {
        $this->monitor->logSecurityBreach($payload);
        $this->alerts->triggerSecurityAlert($payload);
        $this->recovery->isolateAffectedSystems($payload);
    }
}

class SystemEvents
{
    const CRITICAL_ERROR = 'system.critical_error';
    const SECURITY_BREACH = 'system.security_breach';
    const PERFORMANCE_DEGRADATION = 'system.performance_degradation';
}