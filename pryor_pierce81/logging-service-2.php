<?php

namespace App\Core\Logging;

class LoggingService implements CriticalLoggingInterface
{
    private LogStore $logStore;
    private EventFormatter $formatter;
    private IntegrityVerifier $integrityVerifier;
    private LogEncryptor $encryptor;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        LogStore $logStore,
        EventFormatter $formatter,
        IntegrityVerifier $integrityVerifier,
        LogEncryptor $encryptor,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->logStore = $logStore;
        $this->formatter = $formatter;
        $this->integrityVerifier = $integrityVerifier;
        $this->encryptor = $encryptor;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function logCriticalEvent(LogEvent $event): LogResult
    {
        $eventId = $this->initializeLogging($event);
        
        try {
            DB::beginTransaction();

            $this->validateEvent($event);
            $formattedEvent = $this->formatter->format($event);
            $encryptedEvent = $this->encryptor->encrypt($formattedEvent);
            
            $this->verifyIntegrity($encryptedEvent);
            $this->storeEvent($encryptedEvent);

            if ($event->isCritical()) {
                $this->handleCriticalEvent($event);
            }

            DB::commit();
            return new LogResult(['eventId' => $eventId]);

        } catch (LoggingException $e) {
            DB::rollBack();
            $this->handleLoggingFailure($e, $eventId);
            throw new CriticalLoggingException($e->getMessage(), $e);
        }
    }

    private function validateEvent(LogEvent $event): void
    {
        if (!$this->integrityVerifier->validateEvent($event)) {
            throw new EventValidationException('Event validation failed');
        }
    }

    private function verifyIntegrity(EncryptedEvent $event): void
    {
        if (!$this->integrityVerifier->verifyIntegrity($event)) {
            $this->emergency->initiate(EmergencyLevel::CRITICAL);
            throw new IntegrityException('Log integrity verification failed');
        }
    }

    private function handleCriticalEvent(LogEvent $event): void
    {
        $this->alerts->dispatch(new CriticalEventAlert($event));
        $this->emergency->assessThreatLevel($event);
    }

    private function handleLoggingFailure(LoggingException $e, string $eventId): void
    {
        try {
            $this->emergency->handleLoggingFailure($e);
            $this->alerts->dispatchCritical(
                new LoggingFailureAlert($e, $eventId)
            );
        } catch (\Exception $emergencyException) {
            $this->emergency->escalateToHighestLevel();
            throw new CatastrophicFailureException(
                'Failed to handle logging failure',
                previous: $emergencyException
            );
        }
    }
}
