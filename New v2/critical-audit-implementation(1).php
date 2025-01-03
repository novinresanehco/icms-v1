<?php

namespace App\Core\Audit;

class AuditLogger implements AuditLoggerInterface
{
    private AuditStore $store;
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private AuditConfig $config;

    public function log(string $event, array $data = [], string $level = 'info'): void
    {
        $monitorId = $this->metrics->startOperation('audit.log');
        
        try {
            $entry = $this->createAuditEntry($event, $data, $level);
            
            $this->validateEntry($entry);
            $this->storeEntry($entry);
            $this->processEntry($entry);
            
            $this->metrics->recordSuccess($monitorId);
            
        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, $e);
            $this->security->handleAuditFailure($e, $event, $data);
            throw $e;
        }
    }

    public function logSecurity(string $event, array $data = []): void
    {
        $this->log($event, $data, 'security');
    }

    public function logAccess(string $resource, string $action, array $context = []): void
    {
        $this->log('access', [
            'resource' => $resource,
            'action' => $action,
            'context' => $context
        ], 'access');
    }

    public function logChange(string $entity, array $changes, array $context = []): void
    {
        $this->log('change', [
            'entity' => $entity,
            'changes' => $changes,
            'context' => $context
        ], 'change');
    }

    private function createAuditEntry(string $event, array $data, string $level): array
    {
        return [
            'event' => $event,
            'data' => $data,
            'level' => $level,
            'timestamp' => microtime(true),
            'request_id' => request()->id(),
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'url' => request()->url(),
            'method' => request()->method(),
            'session_id' => session()->getId(),
            'environment' => app()->environment(),
        ];
    }

    private function validateEntry(array $entry): void
    {
        // Validate required fields
        $required = ['event', 'data', 'level', 'timestamp'];
        
        foreach ($required as $field) {
            if (!isset($entry[$field])) {
                throw new InvalidAuditEntryException("Missing required field: {$field}");
            }
        }

        // Validate data integrity
        if (!$this->security->validateAuditData($entry['data'])) {
            throw new InvalidAuditDataException('Invalid audit data format');
        }

        // Validate level
        if (!in_array($entry['level'], $this->config->getValidLevels())) {
            throw new InvalidAuditLevelException('Invalid audit level');
        }
    }

    private function storeEntry(array $entry): void
    {
        // Encrypt sensitive data
        $entry['data'] = $this->security->encrypt($entry['data']);
        
        // Store with retry mechanism
        retry(3, fn() => $this->store->save($entry), 100);
    }

    private function processEntry(array $entry): void
    {
        // Check for critical events
        if ($this->isCriticalEvent($entry)) {