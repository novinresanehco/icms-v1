<?php

namespace App\Core\Audit;

class AuditLogger implements AuditLoggerInterface
{
    private Repository $repository;
    private Encryptor $encryptor;
    private MetricsCollector $metrics;
    private EventDispatcher $dispatcher;

    public function logSecurityEvent(
        SecurityEvent $event,
        SecurityContext $context,
        array $data = []
    ): void {
        DB::beginTransaction();
        
        try {
            $logEntry = new AuditLog([
                'event_type' => $event->getType(),
                'severity' => $event->getSeverity(),
                'user_id' => $context->getUserId(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent(),
                'data' => $this->encryptor->encrypt($this->sanitizeData($data)),
                'timestamp' => now(),
                'hash' => $this->generateHash($event, $context, $data)
            ]);

            $this->repository->save($logEntry);
            $this->metrics->incrementSecurityEvent($event->getType());
            
            if ($event->isHighSeverity()) {
                $this->dispatchHighSeverityAlert($event, $context, $data);
            }

            DB::commit();

        } catch (Exception $e) {
            DB::rollBack();
            $this->handleLoggingFailure($e, $event, $context);
            throw new AuditLoggingException(
                'Failed to log security event',
                previous: $e
            );
        }
    }

    public function logAccessAttempt(
        AccessAttempt $attempt,
        SecurityContext $context
    ): void {
        DB::beginTransaction();
        
        try {
            $logEntry = new AuditLog([
                'event_type' => 'access_attempt',
                'severity' => $attempt->isSuccessful() ? 'info' : 'warning',
                'user_id' => $attempt->getUserId(),
                'resource' => $attempt->getResource(),
                'result' => $attempt->getResult(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent(),
                'timestamp' => now(),
                'hash' => $this->generateHash($attempt, $context)
            ]);

            $this->repository->save($logEntry);
            $this->metrics->trackAccessAttempt($attempt);

            if (!$attempt->isSuccessful()) {
                $this->handleFailedAccess($attempt, $context);
            }

            DB::commit();

        } catch (Exception $e) {
            DB::rollBack();
            $this->handleLoggingFailure($e, $attempt, $context);
            throw $e;
        }
    }

    public function logSystemChange(
        SystemChange $change,
        SecurityContext $context
    ): void {
        DB::beginTransaction();
        
        try {
            $logEntry = new AuditLog([
                'event_type' => 'system_change',
                'severity' => $change->getSeverity(),
                'user_id' => $context->getUserId(),
                'component' => $change->getComponent(),
                'change_type' => $change->getType(),
                'before_state' => $this->encryptor->encrypt($change->getBeforeState()),
                'after_state' => $this->encryptor->encrypt($change->getAfterState()),
                'timestamp' => now(),
                'hash' => $this->generateHash($change, $context)
            ]);

            $this->repository->save($logEntry);
            $this->metrics->trackSystemChange($change);

            if ($change->isHighImpact()) {
                $this->notifySystemAdmins($change, $context);
            }

            DB::commit();

        } catch (Exception $e) {
            DB::rollBack();
            $this->handleLoggingFailure($e, $change, $context);
            throw $e;
        }
    }

    private function generateHash($event, SecurityContext $context, array $data = []): string
    {
        return hash_hmac('sha256', json_encode([
            'event' => serialize($event),
            'context' => serialize($context),
            'data' => serialize($data),
            'timestamp' => now()->timestamp
        ]), config('app.key'));
    }

    private function sanitizeData(array $data): array
    {
        $sensitiveFields = ['password', 'token', 'secret', 'key'];
        
        return array_map(function ($item) use ($sensitiveFields) {
            if (is_array($item)) {
                return $this->sanitizeData($item);
            }
            
            if (is_string($item) && $this->containsSensitiveData($item, $sensitiveFields)) {
                return '[REDACTED]';
            }
            
            return $item;
        }, $data);
    }

    private function containsSensitiveData(string $value, array $fields): bool
    {
        foreach ($fields as $field) {
            if (stripos($value, $field) !== false) {
                return true;
            }
        }
        return false;
    }

    private function dispatchHighSeverityAlert(
        SecurityEvent $event,
        SecurityContext $context,
        array $data
    ): void {
        $alert = new SecurityAlert(
            $event,
            $context,
            $this->sanitizeData($data)
        );
        
        $this->dispatcher->dispatch($alert);
    }

    private function handleFailedAccess(
        AccessAttempt $attempt,
        SecurityContext $context
    ): void {
        if ($this->detectBruteForceAttempt($attempt)) {
            $this->logSecurityEvent(
                new SecurityEvent('brute_force_detected'),
                $context,
                ['attempt' => $attempt]
            );
        }
    }

    private function detectBruteForceAttempt(AccessAttempt $attempt): bool
    {
        $recentAttempts = $this->repository->getRecentFailedAttempts(
            $attempt->getIpAddress(),
            minutes: 5
        );
        
        return count($recentAttempts) >= 5;
    }

    private function handleLoggingFailure(
        Exception $e,
        $event,
        SecurityContext $context
    ): void {
        error_log(sprintf(
            'Audit logging failed: %s. Event: %s, Context: %s',
            $e->getMessage(),
            json_encode($event),
            json_encode($context)
        ));
        
        $this->metrics->incrementFailureCount('audit_logging');
    }
}
