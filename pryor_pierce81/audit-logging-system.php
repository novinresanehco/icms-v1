<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Security\SecurityContext;
use App\Core\Interfaces\AuditLoggerInterface;

class AuditLogger implements AuditLoggerInterface 
{
    private SecurityContext $security;
    private string $environmentId;
    private array $config;
    
    public function __construct(
        SecurityContext $security,
        string $environmentId,
        array $config
    ) {
        $this->security = $security;
        $this->environmentId = $environmentId;
        $this->config = $config;
    }

    public function logSecurityEvent(
        string $event,
        array $context = [],
        string $level = 'critical'
    ): void {
        DB::transaction(function() use ($event, $context, $level) {
            $entry = $this->createAuditEntry(
                'security',
                $event,
                $context,
                $level
            );
            
            if ($this->isHighSeverity($level)) {
                $this->notifySecurityTeam($entry);
            }

            if ($this->requiresImmediateAction($event)) {
                $this->triggerIncidentResponse($entry);
            }
        });
    }

    public function logOperationEvent(
        string $operation,
        array $context = [],
        bool $success = true
    ): void {
        DB::transaction(function() use ($operation, $context, $success) {
            $entry = $this->createAuditEntry(
                'operation',
                $operation,
                [
                    ...$context,
                    'success' => $success
                ]
            );

            if (!$success && $this->isHighImpactOperation($operation)) {
                $this->notifySystemAdministrators($entry);
            }
        });
    }

    public function logAccessEvent(
        string $resource,
        string $action,
        bool $granted = true
    ): void {
        $context = [
            'resource' => $resource,
            'action' => $action,
            'granted' => $granted,
            'user' => $this->security->getCurrentUser(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ];

        DB::transaction(function() use ($context) {
            $entry = $this->createAuditEntry('access', 'resource_access', $context);
            
            if (!$context['granted']) {
                $this->handleUnauthorizedAccess($entry);
            }
        });
    }

    public function logDataEvent(
        string $entity,
        string $action,
        array $changes,
        int $entityId
    ): void {
        DB::transaction(function() use ($entity, $action, $changes, $entityId) {
            $entry = $this->createAuditEntry('data', $action, [
                'entity' => $entity,
                'entity_id' => $entityId,
                'changes' => $this->sanitizeChanges($changes)
            ]);

            if ($this->isSensitiveData($entity)) {
                $this->logSensitiveDataAccess($entry);
            }
        });
    }

    protected function createAuditEntry(
        string $type,
        string $event,
        array $context,
        string $level = 'info'
    ): AuditEntry {
        $entry = new AuditEntry([
            'type' => $type,
            'event' => $event,
            'context' => $this->sanitizeContext($context),
            'level' => $level,
            'user_id' => $this->security->getCurrentUserId(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'environment' => $this->environmentId,
            'timestamp' => now(),
            'request_id' => request()->id(),
            'session_id' => session()->getId()
        ]);

        $entry->save();
        
        Log::channel('audit')->info('Audit event recorded', [
            'id' => $entry->id,
            'type' => $type,
            'event' => $event
        ]);

        return $entry;
    }

    protected function sanitizeContext(array $context): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->sanitizeString($value);
            }
            if (is_array($value)) {
                return $this->sanitizeContext($value);
            }
            return $value;
        }, $context);
    }

    protected function sanitizeString(string $value): string
    {
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        return substr($value, 0, 1000);
    }

    protected function sanitizeChanges(array $changes): array
    {
        $sanitized = [];
        foreach ($changes as $field => $change) {
            if ($this->isExcludedField($field)) {
                continue;
            }
            $sanitized[$field] = [
                'old' => $this->sanitizeValue($change['old'] ?? null),
                'new' => $this->sanitizeValue($change['new'] ?? null)
            ];
        }
        return $sanitized;
    }

    protected function isExcludedField(string $field): bool
    {
        return in_array($field, [
            'password',
            'remember_token',
            'api_token'
        ]);
    }

    protected function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->sanitizeString($value);
        }
        if (is_array($value)) {
            return $this->sanitizeContext($value);
        }
        return $value;
    }

    protected function isHighSeverity(string $level): bool
    {
        return in_array($level, ['critical', 'alert', 'emergency']);
    }

    protected function isHighImpactOperation(string $operation): bool
    {
        return in_array($operation, $this->config['high_impact_operations'] ?? []);
    }

    protected function isSensitiveData(string $entity): bool
    {
        return in_array($entity, $this->config['sensitive_entities'] ?? []);
    }

    protected function notifySecurityTeam(AuditEntry $entry): void
    {
        // Implementation depends on notification system
    }

    protected function notifySystemAdministrators(AuditEntry $entry): void
    {
        // Implementation depends on notification system
    }

    protected function handleUnauthorizedAccess(AuditEntry $entry): void
    {
        // Implementation depends on security policies
    }

    protected function triggerIncidentResponse(AuditEntry $entry): void
    {
        // Implementation depends on incident response system
    }

    protected function logSensitiveDataAccess(AuditEntry $entry): void
    {
        // Implementation depends on compliance requirements
    }
}
