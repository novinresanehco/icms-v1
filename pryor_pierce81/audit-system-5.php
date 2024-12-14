<?php

namespace App\Core\Audit;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\AuditException;
use Psr\Log\LoggerInterface;

class AuditSystem implements AuditSystemInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $activeAudits = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function startAudit(string $context): string
    {
        $auditId = $this->generateAuditId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('audit:start');
            
            $this->activeAudits[$auditId] = [
                'context' => $context,
                'start_time' => microtime(true),
                'events' => [],
                'metadata' => [
                    'user_id' => $this->security->getCurrentUser()?->getId(),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent()
                ]
            ];

            $this->logAuditStart($auditId, $context);
            
            DB::commit();
            return $auditId;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuditFailure($auditId, 'start', $context, $e);
            throw $e;
        }
    }

    public function recordEvent(
        string $auditId,
        string $event,
        array $data = []
    ): void {
        if (!isset($this->activeAudits[$auditId])) {
            throw new AuditException('Invalid audit ID');
        }

        try {
            DB::beginTransaction();

            $this->security->validateContext('audit:record');
            $this->validateEventData($data);

            $timestamp = microtime(true);
            $this->activeAudits[$auditId]['events'][] = [
                'event' => $event,
                'data' => $data,
                'timestamp' => $timestamp
            ];

            $this->persistEvent($auditId, $event, $data, $timestamp);
            
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuditFailure($auditId, 'record', $event, $e);
            throw $e;
        }
    }

    public function completeAudit(string $auditId): array
    {
        if (!isset($this->activeAudits[$auditId])) {
            throw new AuditException('Invalid audit ID');
        }

        try {
            DB::beginTransaction();

            $this->security->validateContext('audit:complete');
            
            $audit = $this->activeAudits[$auditId];
            $audit['end_time'] = microtime(true);
            $audit['duration'] = $audit['end_time'] - $audit['start_time'];

            $this->persistAuditCompletion($auditId, $audit);
            $this->logAuditComplete($auditId, $audit);

            unset($this->activeAudits[$auditId]);
            
            DB::commit();
            return $audit;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuditFailure($auditId, 'complete', null, $e);
            throw $e;
        }
    }

    private function validateEventData(array $data): void
    {
        $size = strlen(json_encode($data));
        if ($size > $this->config['max_event_size']) {
            throw new AuditException('Event data exceeds maximum size');
        }

        foreach ($this->config['sensitive_fields'] as $field) {
            if (isset($data[$field])) {
                throw new AuditException('Event contains sensitive data');
            }
        }
    }

    private function persistEvent(
        string $auditId,
        string $event,
        array $data,
        float $timestamp
    ): void {
        DB::table('audit_events')->insert([
            'audit_id' => $auditId,
            'event' => $event,
            'data' => json_encode($data),
            'timestamp' => date('Y-m-d H:i:s', (int)$timestamp),
            'user_id' => $this->security->getCurrentUser()?->getId()
        ]);
    }

    private function persistAuditCompletion(string $auditId, array $audit): void
    {
        DB::table('audits')->where('id', $auditId)->update([
            'end_time' => date('Y-m-d H:i:s', (int)$audit['end_time']),
            'duration' => $audit['duration'],
            'status' => 'completed',
            'event_count' => count($audit['events'])
        ]);
    }

    private function generateAuditId(): string
    {
        return uniqid('audit_', true);
    }

    private function logAuditStart(string $auditId, string $context): void
    {
        $this->logger->info('Audit started', [
            'audit_id' => $auditId,
            'context' => $context,
            'user_id' => $this->security->getCurrentUser()?->getId(),
            'timestamp' => microtime(true)
        ]);
    }

    private function logAuditComplete(string $auditId, array $audit): void
    {
        $this->logger->info('Audit completed', [
            'audit_id' => $auditId,
            'context' => $audit['context'],
            'duration' => $audit['duration'],
            'event_count' => count($audit['events']),
            'timestamp' => microtime(true)
        ]);
    }

    private function handleAuditFailure(
        string $auditId,
        string $operation,
        ?string $context,
        \Exception $e
    ): void {
        $this->logger->error('Audit operation failed', [
            'audit_id' => $auditId,
            'operation' => $operation,
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_event_size' => 65536, // 64KB
            'retention_period' => 90,   // days
            'sensitive_fields' => [
                'password',
                'token',
                'secret',
                'credit_card'
            ],
            'auto_prune' => true,
            'async_processing' => false
        ];
    }
}
