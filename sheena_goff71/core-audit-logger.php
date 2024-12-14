<?php

namespace App\Core\Security;

class AuditLogger implements AuditInterface
{
    private LogRepository $repository;
    private MetricsCollector $metrics;
    private EncryptionService $encryption;
    private IntegrityVerifier $integrity;

    public function __construct(
        LogRepository $repository,
        MetricsCollector $metrics,
        EncryptionService $encryption,
        IntegrityVerifier $integrity
    ) {
        $this->repository = $repository;
        $this->metrics = $metrics;
        $this->encryption = $encryption;
        $this->integrity = $integrity;
    }

    public function logAccess(Request $request, ?User $user = null): void
    {
        $entry = new AuditEntry([
            'type' => AuditType::ACCESS,
            'user_id' => $user?->id,
            'ip_address' => $request->ip(),
            'uri' => $request->getUri(),
            'method' => $request->getMethod(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now(),
            'request_id' => RequestContext::getId()
        ]);

        $this->logSecureEntry($entry);
    }

    public function logOperation(Operation $operation, ?Result $result = null): void
    {
        $entry = new AuditEntry([
            'type' => AuditType::OPERATION,
            'operation' => $operation->getName(),
            'user_id' => $operation->getUserId(),
            'parameters' => $this->sanitizeParameters($operation->getParameters()),
            'result' => $result ? $this->sanitizeResult($result) : null,
            'timestamp' => now(),
            'request_id' => RequestContext::getId()
        ]);

        $this->logSecureEntry($entry);
    }

    public function logSecurityEvent(SecurityEvent $event): void
    {
        $entry = new AuditEntry([
            'type' => AuditType::SECURITY,
            'event_type' => $event->getType(),
            'severity' => $event->getSeverity(),
            'details' => $event->getDetails(),
            'user_id' => $event->getUserId(),
            'ip_address' => $event->getIpAddress(),
            'timestamp' => now(),
            'request_id' => RequestContext::getId()
        ]);

        $this->logSecureEntry($entry);

        if ($event->isCritical()) {
            $this->notifyCriticalEvent($event);
        }
    }

    public function logError(\Throwable $e, array $context = []): void
    {
        $entry = new AuditEntry([
            'type' => AuditType::ERROR,
            'error_class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $this->sanitizeTrace($e->getTraceAsString()),
            'context' => $this->sanitizeContext($context),
            'timestamp' => now(),
            'request_id' => RequestContext::getId()
        ]);

        $this->logSecureEntry($entry);

        if ($e instanceof CriticalException) {
            $this->notifyCriticalError($e, $context);
        }
    }

    public function logDataAccess(string $operation, string $model, $id, ?User $user = null): void
    {
        $entry = new AuditEntry([
            'type' => AuditType::DATA_ACCESS,
            'operation' => $operation,
            'model' => $model,
            'record_id' => $id,
            'user_id' => $user?->id,
            'timestamp' => now(),
            'request_id' => RequestContext::getId()
        ]);

        $this->logSecureEntry($entry);
    }

    public function logAuthentication(User $user, bool $success, string $method): void
    {
        $entry = new AuditEntry([
            'type' => AuditType::AUTHENTICATION,
            'user_id' => $user->id,
            'success' => $success,
            'method' => $method,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now(),
            'request_id' => RequestContext::getId()
        ]);

        $this->logSecureEntry($entry);

        if (!$success) {
            $this->checkFailedLoginAttempts($user);
        }
    }

    private function logSecureEntry(AuditEntry $entry): void
    {
        try {
            // Add integrity hash
            $entry->hash = $this->integrity->generateHash($entry->toArray());
            
            // Encrypt sensitive data
            $entry->encryptSensitiveData($this->encryption);
            
            // Store with transaction
            DB::transaction(function () use ($entry) {
                $this->repository->store($entry);
                $this->metrics->incrementAuditLogs($entry->type);
            });

        } catch (\Exception $e) {
            Log::critical('Failed to store audit log', [
                'entry' => $entry->toArray(),
                'error' => $e->getMessage()
            ]);
            throw new AuditLoggingException('Failed to store audit log', 0, $e);
        }
    }

    private function sanitizeParameters(array $parameters): array
    {
        return collect($parameters)
            ->map(fn($value, $key) => $this->shouldMaskParameter($key) ? '[REDACTED]' : $value)
            ->toArray();
    }

    private function sanitizeResult($result): array
    {
        if ($result instanceof Arrayable) {
            $result = $result->toArray();
        }

        return collect($result)
            ->map(fn($value, $key) => $this->shouldMaskParameter($key) ? '[REDACTED]' : $value)
            ->toArray();
    }

    private function sanitizeTrace(string $trace): string
    {
        return preg_replace('/password=\'[^\']+\'/', 'password=\'[REDACTED]\'', $trace);
    }

    private function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['password', 'token', 'secret', 'key', 'auth'];
        
        return collect($context)
            ->map(function ($value, $key) use ($sensitiveKeys) {
                if (Str::contains($key, $sensitiveKeys)) {
                    return '[REDACTED]';
                }
                return $value;
            })
            ->toArray();
    }

    private function shouldMaskParameter(string $key): bool
    {
        return in_array(strtolower($key), [
            'password', 'token', 'secret', 'key', 'auth',
            'credit_card', 'card_number', 'cvv', 'ssn'
        ]);
    }

    private function checkFailedLoginAttempts(User $user): void
    {
        $attempts = $this->repository->getRecentFailedLogins($user->id, now()->subHour());
        
        if ($attempts >= 5) {
            event(new ExcessiveFailedLogins($user));
        }
    }

    private function notifyCriticalEvent(SecurityEvent $event): void
    {
        event(new CriticalSecurityEvent($event));
    }

    private function notifyCriticalError(\Throwable $e, array $context): void
    {
        event(new CriticalErrorOccurred($e, $context));
    }
}

interface AuditInterface
{
    public function logAccess(Request $request, ?User $user = null): void;
    public function logOperation(Operation $operation, ?Result $result = null): void;
    public function logSecurityEvent(SecurityEvent $event): void;
    public function logError(\Throwable $e, array $context = []): void;
    public function logDataAccess(string $operation, string $model, $id, ?User $user = null): void;
    public function logAuthentication(User $user, bool $success, string $method): void;
}
