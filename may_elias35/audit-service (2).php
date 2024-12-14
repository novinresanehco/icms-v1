<?php

namespace App\Core\Audit;

use App\Core\Interfaces\AuditInterface;
use App\Core\Exceptions\{
    AuditException,
    SecurityException,
    ValidationException
};
use Illuminate\Support\Facades\{DB, Log, Cache};

class AuditService implements AuditInterface
{
    private SecurityService $security;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private array $config;

    private const AUDIT_PREFIX = 'audit:';
    private const RETENTION_DAYS = 365;
    private const BATCH_SIZE = 1000;
    private const SEVERITY_LEVELS = ['critical', 'high', 'medium', 'low'];

    public function __construct(
        SecurityService $security,
        ValidationService $validator,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function logSecurityEvent(string $eventType, array $data): void
    {
        $eventId = $this->generateEventId();
        
        try {
            $this->validateSecurityEvent($eventType, $data);
            $this->enforceAuditPolicy($eventType);

            $event = $this->createSecurityEvent($eventId, $eventType, $data);
            $this->persistSecurityEvent($event);
            
            $this->notifySecurityTeam($event);
            $this->updateSecurityMetrics($event);

        } catch (\Exception $e) {
            $this->handleAuditFailure($eventId, $eventType, $e);
            throw new AuditException(
                'Failed to log security event: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function logOperation(array $data): void
    {
        $operationId = $data['operation_id'];

        DB::beginTransaction();
        
        try {
            $this->validateOperationData($data);
            $this->checkAuditQuota();

            $record = $this->createAuditRecord($operationId, $data);
            $this->persistAuditRecord($record);
            
            $this->archiveIfNeeded($record);
            $this->updateMetrics($record);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($operationId, $e);
            throw new AuditException(
                'Failed to log operation: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function queryAuditTrail(array $criteria): array
    {
        try {
            $this->validateQueryCriteria($criteria);
            $this->enforceQueryPolicy($criteria);

            $records = $this->executeAuditQuery($criteria);
            $this->validateQueryResults($records);

            return $records;

        } catch (\Exception $e) {
            $this->handleQueryFailure($criteria, $e);
            throw new AuditException(
                'Audit trail query failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    protected function validateSecurityEvent(string $eventType, array $data): void
    {
        if (!$this->validator->validateEventType($eventType)) {
            throw new ValidationException('Invalid security event type');
        }

        if (!$this->validator->validateEventData($data)) {
            throw new ValidationException('Invalid security event data');
        }

        if (!$this->security->validateContext($data['context'] ?? [])) {
            throw new SecurityException('Invalid security context');
        }
    }

    protected function enforceAuditPolicy(string $eventType): void
    {
        $policy = $this->config['audit_policies'][$eventType] ?? null;
        if (!$policy) {
            throw new AuditException('No audit policy defined for event type');
        }

        if (!$this->checkRetentionPolicy($policy)) {
            throw new AuditException('Retention policy check failed');
        }

        if (!$this->checkCompliancePolicy($policy)) {
            throw new AuditException('Compliance policy check failed');
        }
    }

    protected function createSecurityEvent(
        string $eventId,
        string $eventType,
        array $data
    ): array {
        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'severity' => $this->calculateSeverity($eventType, $data),
            'timestamp' => microtime(true),
            'node_id' => gethostname(),
            'data' => $data,
            'context' => $this->security->getSecurityContext(),
            'metadata' => $this->generateEventMetadata($eventType)
        ];
    }

    protected function persistSecurityEvent(array $event): void
    {
        DB::table('security_events')->insert([
            'event_id' => $event['event_id'],
            'event_type' => $event['event_type'],
            'severity' => $event['severity'],
            'timestamp' => $event['timestamp'],
            'data' => json_encode($event['data']),
            'context' => json_encode($event['context']),
            'metadata' => json_encode($event['metadata'])
        ]);

        Cache::put(
            $this->getEventKey($event['event_id']),
            $event,
            now()->addDays(self::RETENTION_DAYS)
        );
    }

    protected function createAuditRecord(string $operationId, array $data): array
    {
        return [
            'record_id' => $this->generateRecordId(),
            'operation_id' => $operationId,
            'timestamp' => microtime(true),
            'type' => $data['type'],
            'user_id' => $data['user_id'] ?? null,
            'action' => $data['action'],
            'resources' => $data['resources'] ?? [],
            'changes' => $data['changes'] ?? [],
            'metadata' => $this->generateRecordMetadata($data)
        ];
    }

    protected function persistAuditRecord(array $record): void
    {
        DB::table('audit_records')->insert([
            'record_id' => $record['record_id'],
            'operation_id' => $record['operation_id'],
            'timestamp' => $record['timestamp'],
            'type' => $record['type'],
            'user_id' => $record['user_id'],
            'action' => $record['action'],
            'resources' => json_encode($record['resources']),
            'changes' => json_encode($record['changes']),
            'metadata' => json_encode($record['metadata'])
        ]);
    }

    protected function executeAuditQuery(array $criteria): array
    {
        $query = DB::table('audit_records')
            ->when(isset($criteria['start_date']), function ($q) use ($criteria) {
                $q->where('timestamp', '>=', $criteria['start_date']);
            })
            ->when(isset($criteria['end_date']), function ($q) use ($criteria) {
                $q->where('timestamp', '<=', $criteria['end_date']);
            })
            ->when(isset($criteria['type']), function ($q) use ($criteria) {
                $q->where('type', $criteria['type']);
            })
            ->when(isset($criteria['user_id']), function ($q) use ($criteria) {
                $q->where('user_id', $criteria['user_id']);
            })
            ->orderBy('timestamp', 'desc')
            ->limit($criteria['limit'] ?? self::BATCH_SIZE);

        return $query->get()->toArray();
    }

    protected function calculateSeverity(string $eventType, array $data): string
    {
        $severityRules = $this->config['severity_rules'][$eventType] ?? [];
        
        foreach ($severityRules as $severity => $conditions) {
            if ($this->matchesConditions($data, $conditions)) {
                return $severity;
            }
        }

        return 'low';
    }

    protected function matchesConditions(array $data, array $conditions): bool
    {
        foreach ($conditions as $field => $value) {
            if (!isset($data[$field]) || $data[$field] !== $value) {
                return false;
            }
        }
        return true;
    }

    protected function generateRecordId(): string
    {
        return uniqid(self::AUDIT_PREFIX, true);
    }

    protected function generateEventId(): string
    {
        return uniqid('event:', true);
    }

    protected function getEventKey(string $eventId): string
    {
        return self::AUDIT_PREFIX . $eventId;
    }

    protected function generateEventMetadata(string $eventType): array
    {
        return [
            'timestamp' => microtime(true),
            'node_id' => gethostname(),
            'version' => $this->config['version'],
            'environment' => $this->config['environment']
        ];
    }

    protected function generateRecordMetadata(array $data): array
    {
        return [
            'timestamp' => microtime(true),
            'node_id' => gethostname(),
            'version' => $this->config['version'],
            'source' => $data['source'] ?? 'system'
        ];
    }
}
