<?php

namespace App\Core\Escalation;

class CriticalEscalationSystem implements EscalationInterface
{
    private AlertManager $alerts;
    private ResponseCoordinator $coordinator;
    private SecurityController $security;
    private EmergencyProtocol $emergency;

    public function escalate(CriticalEvent $event): EscalationResponse
    {
        DB::beginTransaction();

        try {
            // Immediate alert dispatch
            $this->dispatchAlert($event);
            
            // Security measures activation
            $this->activateSecurityProtocol($event);
            
            // Coordinate response
            $this->coordinateResponse($event);
            
            // Emergency protocol if needed
            $this->checkEmergencyTrigger($event);

            DB::commit();
            return new EscalationResponse(true);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleEscalationFailure($e, $event);
            throw new EscalationException('Critical escalation failed', 0, $e);
        }
    }

    private function dispatchAlert(CriticalEvent $event): void
    {
        $this->alerts->dispatchCritical([
            'event' => $event,
            'severity' => 'critical',
            'timestamp' => now(),
            'requires_immediate_action' => true
        ]);
    }

    private function activateSecurityProtocol(CriticalEvent $event): void
    {
        $this->security->activateEmergencyMode();
        $this->security->lockdownAffectedSystems($event->getAffectedSystems());
        $this->security->enableMaximumProtection();
    }

    private function coordinateResponse(CriticalEvent $event): void
    {
        $response = $this->coordinator->initiate($event);
        
        if (!$response->isSuccessful()) {
            throw new CoordinationException('Response coordination failed');
        }
    }

    private function checkEmergencyTrigger(CriticalEvent $event): void
    {
        if ($event->isSystemCritical() || $event->threatLevel() === 'severe') {
            $this->emergency->activate([
                'event' => $event,
                'timestamp' => now(),
                'escalation_level' => 'maximum'
            ]);
        }
    }

    private function handleEscalationFailure(\Exception $e, CriticalEvent $event): void
    {
        Log::emergency('Escalation failure', [
            'error' => $e->getMessage(),
            'event' => $event->toArray(),
            'trace' => $e->getTraceAsString()
        ]);

        try {
            $this->activateFailsafe($e, $event);
        } catch (\Exception $failsafeError) {
            $this->handleCatastrophicFailure($failsafeError);
        }
    }

    private function activateFailsafe(\Exception $e, CriticalEvent $event): void
    {
        $this->emergency->activateFailsafe([
            'original_error' => $e,
            'event' => $event,
            'timestamp' => now()
        ]);

        $this->alerts->notifyEmergencyTeam([
            'type' => 'failsafe_activated',
            'severity' => 'critical',
            'error' => $e->getMessage()
        ]);
    }

    private function handleCatastrophicFailure(\Exception $e): void
    {
        Log::emergency('Catastrophic failure - all systems affected', [
            'error' => $e->getMessage(),
            'timestamp' => now(),
            'state' => $this->captureSystemState()
        ]);

        try {
            $this->emergency->catastrophicShutdown();
        } catch (\Exception $shutdownError) {
            // Last resort logging
            Log::emergency('Shutdown failed - system in unknown state', [
                'error' => $shutdownError->getMessage()
            ]);
        }
    }

    private function captureSystemState(): array
    {
        return [
            'security_status' => $this->security->getCurrentStatus(),
            'active_alerts' => $this->alerts->getActiveAlerts(),
            'system_health' => $this->coordinator->getSystemHealth(),
            'emergency_status' => $this->emergency->getCurrentStatus()
        ];
    }
}
