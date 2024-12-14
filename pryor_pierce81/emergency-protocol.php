<?php

namespace App\Core\Emergency;

class EmergencyProtocolService implements EmergencyProtocolInterface
{
    private LockdownManager $lockdownManager;
    private IncidentHandler $incidentHandler;
    private NotificationSystem $notificationSystem;
    private RecoveryController $recoveryController;
    private EmergencyLogger $logger;
    private AlertSystem $alerts;

    public function __construct(
        LockdownManager $lockdownManager,
        IncidentHandler $incidentHandler,
        NotificationSystem $notificationSystem,
        RecoveryController $recoveryController,
        EmergencyLogger $logger,
        AlertSystem $alerts
    ) {
        $this->lockdownManager = $lockdownManager;
        $this->incidentHandler = $incidentHandler;
        $this->notificationSystem = $notificationSystem;
        $this->recoveryController = $recoveryController;
        $this->logger = $logger;
        $this->alerts = $alerts;
    }

    public function initiateEmergencyProtocol(EmergencyContext $context): EmergencyResponse
    {
        $emergencyId = $this->initializeEmergency($context);
        
        try {
            DB::beginTransaction();

            $this->engageImmediateLockdown($context);
            $incident = $this->createIncident($context);
            
            $this->notifyEmergencyContacts($incident);
            $recoveryPlan = $this->prepareRecoveryPlan($incident);

            $response = new EmergencyResponse([
                'emergencyId' => $emergencyId,
                'incident' => $incident,
                'recoveryPlan' => $recoveryPlan,
                'status' => EmergencyStatus::ACTIVE,
                'timestamp' => now()
            ]);

            DB::commit();
            return $response;

        } catch (EmergencyException $e) {
            DB::rollBack();
            $this->handleCatastrophicFailure($e, $emergencyId);
            throw new SystemFailureException($e->getMessage(), $e);
        }
    }

    private function engageImmediateLockdown(EmergencyContext $context): void
    {
        $this->lockdownManager->initiateLockdown([
            'level' => LockdownLevel::CRITICAL,
            'scope' => LockdownScope::FULL_SYSTEM,
            'immediate' => true
        ]);

        $this->logger->logLockdown($context);
        $this->alerts->dispatchCriticalAlert(
            new SystemLockdownAlert($context)
        );
    }

    private function createIncident(EmergencyContext $context): Incident
    {
        return $this->incidentHandler->createIncident([
            'severity' => IncidentSeverity::CRITICAL,
            'type' => IncidentType::SYSTEM_EMERGENCY,
            'context' => $context,
            'priority' => IncidentPriority::HIGHEST
        ]);
    }

    private function notifyEmergencyContacts(Incident $incident): void
    {
        $this->notificationSystem->dispatchEmergencyNotifications([
            'channels' => ['sms', 'email', 'phone'],
            'priority' => NotificationPriority::CRITICAL,
            'incident' => $incident
        ]);
    }

    private function handleCatastrophicFailure(
        EmergencyException $e,
        string $emergencyId
    ): void {
        $this->logger->logCatastrophicFailure($e, $emergencyId);
        
        try {
            $this->notificationSystem->dispatchCatastrophicFailureAlert($e);
            $this->lockdownManager->engageFailsafeProtocol();
        } catch (\Exception $secondaryException) {
            $this->logger->logTotalSystemFailure([
                'primary_exception' => $e,
                'secondary_exception' => $secondaryException
            ]);
        }
    }
}
