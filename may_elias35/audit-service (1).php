<?php

namespace App\Core\Audit;

use App\Core\Security\SecurityContext;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\DB;

class AuditLogger implements AuditInterface
{
    private ValidationService $validator;
    private array $config;

    private const MAX_RETRY_ATTEMPTS = 3;
    private const CRITICAL_EVENTS = [
        'security_breach',
        'data_corruption',
        'system_failure'
    ];

    public function __construct(
        ValidationService $validator,
        array $config
    ) {
        $this->validator = $validator;
        $this->config = $config;
    }

    public function logSecurityEvent(
        string $event,
        array $data,
        SecurityContext $context
    ): void {
        DB::beginTransaction();
        
        try {
            // Validate event data
            $this->validateSecurityEvent($event, $data, $context);
            
            // Prepare audit entry
            $entry = $this->prepareSecurityAudit($event, $data, $context);
            
            // Store entry with retry mechanism
            $this->storeAuditEntry($entry);
            
            DB::commit();
            
            // Handle critical events
            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($entry);
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleLoggingFailure($e, $event, $data);
            throw $e;
        }
    }

    public function logSystemEvent(
        string $event,
        array $data
    ): void {
        try {
            // Validate system event
            $this->validateSystemEvent($event, $data);
            
            // Prepare entry
            $entry = $this->prepareSystemAudit($event, $data);
            
            // Store entry
            $this->storeAuditEntry($entry);
            
        } catch (\Exception $e) {
            $this->handleLoggingFailure($e, $event, $data);
            throw $e;
        }
    }

    private function validateSecurityEvent(
        string $event,
        array $data,
        SecurityContext $context
    ): void {
        if (!$this->validator->validateSecurityEvent($event, $data)) {
            throw new AuditValidationException('Invalid security event data');
        }

        if (!$context->isValid()) {
            throw new AuditSecurityException('Invalid security context');
        }
    }

    private function validateSystemEvent(
        string $event,
        array $data
    ): void {
        if (!$this->validator->validateSystemEvent($event, $data)) {
            throw new AuditValidationException('Invalid system event data');
        }
    }

    private function prepareSecurityAudit(
        string $event,
        array $data,
        SecurityContext $context
    ): array {
        return [
            'type' => 'security',
            'event' => $event,
            'data' => $this->sanitizeData($data),
            'context' => [
                'user_id' => $context->getUserId(),
                'ip_address' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent()
            ],
            'timestamp' => now(),
            'severity' => $this->calculateSeverity($event)
        ];
    }

    private function prepareSystemAudit(
        string $event,
        array $data
    ): array {
        return [
            'type' => 'system',
            'event' => $event,
            'data' => $this->sanitizeData($data),
            'timestamp' => now(),
            'severity' => $this->calculateSeverity($event)
        ];
    }

    private function storeAuditEntry(array $entry): void
    {
        $attempts = 0;
        
        while ($attempts < self::MAX_RETRY_ATTEMPTS) {
            try {
                DB::table('audit_logs')->insert($entry);
                return;
            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= self::MAX_RETRY_ATTEMPTS) {
                    throw new AuditStorageException(
                        'Failed to store audit entry after retries',
                        previous: $e
                    );
                }
                usleep(100000 * $attempts);
            }
        }
    }

    private function sanitizeData(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if ($this->isSensitiveField($key)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $this->sanitizeValue($value);
            }
        }

        return $sanitized;
    }

    private function sanitizeValue($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'sanitizeValue'], $value);
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        return $value;
    }

    private function sanitizeString(string $value): string
    {
        // Remove potential sensitive patterns
        $value = preg_replace('/\b[\w\.-]+@[\w\.-]+\.\w{2,4}\b/', '[EMAIL]', $value);
        $value = preg_replace('/\b\d{16}\b/', '[CARD]', $value);
        
        // Sanitize HTML/JavaScript
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function isSensitiveField(string $field): bool
    {
        return in_array($field, $this->config['sensitive_fields']);
    }

    private function calculateSeverity(string $event): int
    {
        if (in_array($event, self::CRITICAL_EVENTS)) {
            return 1; // Critical
        }

        return isset($this->config['event_severity'][$event])
            ? $this->config['event_severity'][$event]
            : 3; // Default severity
    }

    private function isCriticalEvent(string $event): bool
    {
        return in_array($event, self::CRITICAL_EVENTS);
    }

    private function handleCriticalEvent(array $entry): void
    {
        // Notify security team
        $this->notifySecurityTeam($entry);
        
        // Store in separate critical events log
        $this->storeCriticalEvent($entry);
        
        // Trigger system alerts
        $this->triggerSystemAlerts($entry);
    }

    private function handleLoggingFailure(
        \Exception $e,
        string $event,
        array $data
    ): void {
        try {
            // Attempt to log to emergency backup
            $this->logToEmergencyBackup([
                'error