<?php

namespace App\Core\Events;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\SystemException;

class SystemEventService
{
    protected SecurityManager $security;
    protected array $criticalEvents = [
        'system.failure',
        'security.breach',
        'data.corruption',
        'auth.compromise'
    ];

    protected array $handlers = [];
    protected bool $emergencyMode = false;

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
        $this->initializeHandlers();
    }

    public function dispatch(string $event, array $data = []): void
    {
        DB::beginTransaction();
        
        try {
            $this->validateEvent($event, $data);
            $eventData = $this->prepareEvent($event, $data);
            
            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($eventData);
            }

            $this->processEvent($eventData);
            $this->notifyListeners($eventData);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleEventFailure($e, $event, $data);
            throw $e;
        }
    }

    protected function validateEvent(string $event, array $data): void
    {
        if (!$this->security->validateEventAccess($event)) {
            throw new SystemException('Event access denied');
        }

        if ($this->emergencyMode && !$this->isCriticalEvent($event)) {
            throw new SystemException('Only critical events allowed in emergency mode');
        }
    }

    protected function prepareEvent(string $event, array $data): array
    {
        return [
            'id' => $this->generateEventId(),
            'type' => $event,
            'data' => $data,
            'timestamp' => now(),
            'user_id' => auth()->id(),
            'session_id' => session()->getId(),
            'system_state' => $this->captureSystemState()
        ];
    }

    protected function handleCriticalEvent(array $eventData): void
    {
        $this->security->escalateSecurityLevel();
        $this->notifyAdministrators($eventData);
        
        if ($this->requiresEmergencyMode($eventData)) {
            $this->activateEmergencyMode();
        }

        Log::critical('Critical system event', $eventData);
    }

    protected function processEvent(array $eventData): void
    {
        foreach ($this->handlers[$eventData['type']] ?? [] as $handler) {
            try {
                $handler->handle($eventData);
            } catch (\Exception $e) {
                Log::error('Event handler failed', [
                    'handler' => get_class($handler),
                    'event' => $eventData,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    protected function notifyListeners(array $eventData): void
    {
        $listeners = Cache::tags(['events'])
            ->get("listeners.{$eventData['type']}", []);

        foreach ($listeners as $listener) {
            $this->dispatchToListener($listener, $eventData);
        }
    }

    protected function handleEventFailure(\Exception $e, string $event, array $data): void
    {
        Log::error('Event processing failed', [
            'event' => $event,
            'data' => $data,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isCriticalEvent($event)) {
            $this->security->handleCriticalFailure($e);
        }
    }

    protected function activateEmergencyMode(): void
    {
        $this->emergencyMode = true;
        Cache::forever('system.emergency_mode', true);
        $this->security->enterEmergencyMode();
    }

    protected function generateEventId(): string
    {
        return uniqid('evt_', true);
    }

    protected function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'system_load' => sys_getloadavg(),
            'active_users' => Cache::get('stats.active_users', 0),
            'error_rate' => Cache::get('stats.error_rate', 0)
        ];
    }

    protected function initializeHandlers(): void
    {
        foreach (config('events.handlers', []) as $event => $handlers) {
            foreach ($handlers as $handler) {
                $this->registerHandler($event, new $handler());
            }
        }
    }
}
