<?php

namespace App\Core\Notification;

class NotificationAlertingSystem implements NotificationInterface
{
    private AlertManager $alerts;
    private NotificationDispatcher $dispatcher;
    private EscalationEngine $escalation;
    private PriorityManager $priority;
    private EmergencyNotifier $emergency;

    public function __construct(
        AlertManager $alerts,
        NotificationDispatcher $dispatcher,
        EscalationEngine $escalation,
        PriorityManager $priority,
        EmergencyNotifier $emergency
    ) {
        $this->alerts = $alerts;
        $this->dispatcher = $dispatcher;
        $this->escalation = $escalation;
        $this->priority = $priority;
        $this->emergency = $emergency;
    }

    public function dispatchCriticalAlert(CriticalAlert $alert): AlertResult
    {
        $alertId = $this->initializeAlert();
        DB::beginTransaction();

        try {
            // Validate alert
            $validation = $this->validateAlert($alert);
            if (!$validation->isValid()) {
                throw new ValidationException('Alert validation failed');
            }

            // Set priority level
            $priorityLevel = $this->priority->calculatePriority($alert);
            if ($priorityLevel->isCritical()) {
                $this->handleCriticalPriority($alert, $priorityLevel);
            }

            // Dispatch alert
            $dispatchResult = $this->dispatchAlert($alert, $priorityLevel);

            // Verify delivery
            $verificationResult = $this->verifyAlertDelivery($dispatchResult);
            if (!$verificationResult->isDelivered()) {
                throw new DeliveryException('Alert delivery verification failed');
            }

            $this->logAlertDispatch($alertId, $dispatchResult);
            DB::commit();

            return new AlertResult(
                success: true,
                alertId: $alertId,
                dispatch: $dispatchResult,
                verification: $verificationResult
            );

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAlertFailure($alertId, $alert, $e);
            throw $e;
        }
    }

    private function dispatchAlert(
        CriticalAlert $alert,
        PriorityLevel $priority
    ): DispatchResult {
        // Create dispatch plan
        $plan = $this->dispatcher->createDispatchPlan($alert, $priority);

        // Execute dispatch
        $result = $this->dispatcher->executeDispatch($plan);
        if (!$result->isSuccessful()) {
            throw new DispatchException('Alert dispatch failed');
        }

        // Start escalation timer
        $this->escalation->startEscalationTimer($result);

        return $result;
    }

    private function verifyAlertDelivery(DispatchResult $dispatch): VerificationResult
    {
        // Verify all channels
        $channelVerification = $this->dispatcher->verifyChannels($dispatch);
        if (!$channelVerification->isComplete()) {
            throw new ChannelException('Alert channel verification failed');
        }

        // Verify recipient delivery
        $recipientVerification = $this->dispatcher->verifyRecipients($dispatch);
        if (!$recipientVerification->isComplete()) {
            throw new RecipientException('Alert recipient verification failed');
        }

        return new VerificationResult(
            delivered: true,
            channels: $channelVerification,
            recipients: $recipientVerification
        );
    }

    private function handleCriticalPriority(
        CriticalAlert $alert,
        PriorityLevel $priority
    ): void {
        $this->emergency->initiateCriticalProtocol([
            'alert' => $alert,
            'priority' => $priority,
            'timestamp' => now()
        ]);
    }

    private function handleAlertFailure(
        string $alertId,
        CriticalAlert $alert,
        \Exception $e
    ): void {
        Log::critical('Alert dispatch failed', [
            'alert_id' => $alertId,
            'alert' => $alert->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->emergency->handleAlertFailure(
            $alertId,
            $alert,
            $e
        );

        // Attempt alternate delivery
        $this->attemptAlternateDelivery($alertId, $alert);
    }

    private function attemptAlternateDelivery(
        string $alertId,
        CriticalAlert $alert
    ): void {
        try {
            $alternateChannels = $this->dispatcher->getAlternateChannels();
            $this->dispatcher->dispatchToAlternates($alert, $alternateChannels);
        } catch (\Exception $e) {
            Log::emergency('Alternate alert delivery failed', [
                'alert_id' => $alertId,
                'error' => $e->getMessage()
            ]);
            $this->emergency->escalateDeliveryFailure($alertId, $e);
        }
    }

    private function validateAlert(CriticalAlert $alert): ValidationResult
    {
        $violations = [];

        // Validate content
        if (!$this->alerts->validateContent($alert)) {
            $violations[] = new ContentViolation('Invalid alert content');
        }

        // Validate recipients
        if (!$this->alerts->validateRecipients($alert)) {
            $violations[] = new RecipientViolation('Invalid alert recipients');
        }

        // Validate channels
        if (!$this->alerts->validateChannels($alert)) {
            $violations[] = new ChannelViolation('Invalid alert channels');
        }

        return new ValidationResult(
            valid: empty($violations),
            violations: $violations
        );
    }

    private function initializeAlert(): string
    {
        return Str::uuid();
    }

    private function logAlertDispatch(string $alertId, DispatchResult $result): void
    {
        Log::info('Alert dispatched', [
            'alert_id' => $alertId,
            'channels' => $result->getChannels(),
            'recipients' => $result->getRecipients(),
            'timestamp' => now()
        ]);
    }
}
