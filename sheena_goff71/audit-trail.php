<?php

namespace App\Core\Audit;

use App\Core\Security\SecurityManager;
use App\Core\Database\DatabaseManager;
use App\Exceptions\AuditException;

class AuditTrail implements AuditInterface
{
    private SecurityManager $security;
    private DatabaseManager $database;
    private array $config;
    private array $buffer = [];

    public function __construct(
        SecurityManager $security,
        DatabaseManager $database,
        array $config
    ) {
        $this->security = $security;
        $this->database = $database;
        $this->config = $config;
    }

    public function logAccess(AccessContext $context): void
    {
        $entry = $this->createAuditEntry('access', [
            'user_id' => $context->userId,
            'resource' => $context->resource,
            'action' => $context->action,
            'result' => $context->result,
            'ip_address' => $context->ipAddress,
            'user_agent' => $context->userAgent
        ]);

        $this->storeAuditEntry($entry);
    }

    public function logOperation(OperationContext $context): void
    {
        $entry = $this->createAuditEntry('operation', [
            'operation_id' => $context->operationId,
            'type' => $context->type,
            'parameters' => $context->parameters,
            'result' => $context->result,
            'duration' => $context->duration,
            'status' => $context->status
        ]);

        $this->storeAuditEntry($entry);
    }

    public function logSecurity(SecurityEvent $event): void
    {
        $entry = $this->createAuditEntry('security', [
            'event_type' => $event->type,
            'severity' => $event->severity,
            'details' => $event->details,
            'affected_resources' => $event->affectedResources,
            'mitigation' => $event->mitigation
        ]);

        $this->storeAuditEntry($entry, true); // Force immediate storage for security events
    }

    public function generateAuditReport(array $filters = []): AuditReport
    {
        try {
            // Flush any buffered entries
            $this->flushBuffer();
            
            // Retrieve audit entries
            $entries = $this->retrieveAuditEntries($filters);
            
            // Analyze entries
            $analysis = $this->analyzeAuditEntries($entries);
            
            // Generate report
            return new AuditReport([
                'entries' => $entries,
                'analysis' => $analysis,
                'generated_at' => now(),
                'filters' => $filters
            ]);
            
        } catch (\Throwable $e) {
            throw new AuditException('Failed to generate audit report: ' . $e->getMessage(), 0, $e);
        }
    }

    private function createAuditEntry(string $type, array $data): array
    {
        return [
            'id' => $this->generateAuditId(),
            'type' => $type,
            'data' => $data,
            'timestamp' => microtime(true),
            'user_id' => $this->security->getCurrentUserId(),
            'session_id' => session_id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'checksum' => $this->generateEntryChecksum($type, $data)
        ];
    }

    private function storeAuditEntry(array $entry, bool $immediate = false): void
    {
        if ($immediate || count($this->buffer) >= $this->config['buffer_size']) {
            $this->flushBuffer();
        }

        $this->buffer[] = $entry;
    }

    private function flushBuffer(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        try {
            $this->database->transaction(function() {
                foreach ($this->buffer as $entry) {
                    $this->database->table('audit_log')->insert($entry);
                }
            });
            
            $this->buffer = [];
            
        } catch (\Throwable $e) {
            throw new AuditException('Failed to store audit entries: ' . $e->getMessage(), 0, $e);
        }
    }

    private function retrieveAuditEntries(array $filters): array
    {
        $query = $this->database->table('audit_log');
        
        foreach ($filters as $field => $value) {
            if ($field === 'date_range') {
                $query->whereBetween('timestamp', [$value['start'], $value['end']]);
            } elseif ($field === 'types') {
                $query->whereIn('type', $value);
            } else {
                $query->where($field, $value);
            }
        }
        
        return $query->orderBy('timestamp', 'desc')->get()->toArray();
    }

    private function analyzeAuditEntries(array $entries): array
    {
        return [
            'total_entries' => count($entries),
            'entry_types' => $this->countEntryTypes($entries),
            'time_distribution' => $this->analyzeTimeDistribution($entries),
            'user_activity' => $this->analyzeUserActivity($entries),
            'security_events' => $this->analyzeSecurityEvents($entries),
            'anomalies' => $this->detectAnomalies($entries)
        ];
    }

    private function generateAuditId(): string
    {
        return uniqid('audit_', true);
    }

    private function generateEntryChecksum(string $type, array $data): string
    {
        return hash_hmac(
            'sha256',
            $type . serialize($data),
            $this->config['hmac_key']
        );
    }
}
