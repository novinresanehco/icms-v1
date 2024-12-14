<?php

namespace App\Core\Escalation;

class EscalationService implements EscalationInterface
{
    private SeverityAnalyzer $severityAnalyzer;
    private NotificationManager $notificationManager;
    private ResponseCoordinator $responseCoordinator;
    private EscalationLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        SeverityAnalyzer $severityAnalyzer,
        NotificationManager $notificationManager,
        ResponseCoordinator $responseCoordinator,
        EscalationLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->severityAnalyzer = $severityAnalyzer;
        $this->notificationManager = $notificationManager;
        $this->responseCoordinator = $responseCoordinator;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function handleEscalation(EscalationContext $context): EscalationResult
    {
        $escalationId = $this->initializeEscalation($context);
        
        try {
            DB::beginTransaction();

            $severity = $this->analyzeSeverity($context);
            $this->validateEscalationLevel($severity);
            $this->notifyStakeholders($context, $severity);
            $this->coordinateResponse($context, $severity);

            if ($severity->isCritical()) {
                $this->initiateCriticalResponse($context);
            }

            $result = new EscalationResult([
                'escalationId' => $escalationId,
                'severity' => $severity,
                'status' => EscalationStatus::HANDLED,
                'timestamp' => now()
            ]);

            DB::commit();
            $this->finalizeEscalation($result);

            return $result;

        } catch (EscalationException $e) {
            DB::rollBack();
            $this->handleEscalationFailure($e, $escalationId);
            throw new CriticalEscalationException($e->getMessage(), $e);
        }
    }

    private function analyzeSeverity(EscalationContext $context): SeverityLevel
    {
        return $this->severityAnalyzer->analyze([
            'impact' => $context->getImpact(),
            'urgency' => $context->getUrgency(),
            'scope' => $context->getScope()
        ]);
    }

    private function validateEscalationLevel(SeverityLevel $severity): void
    {
        if ($severity->isUnacceptable()) {
            $this->emergency->initiateEmergencyProtocol();
            throw new UnacceptableSeverityException('Severity level exceeds acceptable threshold');
        }
    }

    private function initiateCriticalResponse(EscalationContext $context): void
    {
        $this->emergency->activateEmergencyResponse();
        $this->alerts->dispatchCriticalAlert(new CriticalAlert($context));
        $this->notificationManager->notifyEmergencyTeam($context);
        $this->logger->logCriticalResponse($context);
    }

    private function handleEscalationFailure(EscalationException $e, string $escalationId): void
    {
        $this->logger->logFailure($e, $escalationId);
        $this->emergency->escalateToHighestLevel();
        
        $this->alerts->dispatchCriticalAlert(
            new EscalationFailureAlert([
                'escalationId' => $escalationId,
                'exception' => $e
            ])
        );

        try {
            $this->notificationManager->notifyCommandChain($e);
        } catch (\Exception $notificationException) {
            $this->logger->logCommunicationFailure($notificationException, $escalationId);
        }
    }
}
