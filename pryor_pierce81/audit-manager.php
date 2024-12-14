<?php

namespace App\Core\Audit;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\AuditException;
use Psr\Log\LoggerInterface;

class AuditManager implements AuditManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $activeAuditors = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function logCriticalOperation(string $operation, array $context): void
    {
        $auditId = $this->generateAuditId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('audit:critical', $context);
            $this->validateAuditContext($context);

            $entry = $this->createAuditEntry($operation, $context, AuditLevel::CRITICAL);
            $this->processAuditEntry($entry);
            $this->notifyAuditSubscribers($entry);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuditFailure($auditId, $operation, $e);
            throw new AuditException('Critical audit logging failed', 0, $e);
        }
    }

    public function logSecurityEvent(SecurityEvent $event): void
    {
        $eventId = $this->generateEventId();

        try {
            DB::beginTransaction();

            $this->validateSecurityEvent($event);
            $this->enrichSecurityEvent($event);

            $entry = $this->createSecurityAuditEntry($event);
            $this->processSecurityAudit($entry);

            if ($event->isCritical()) {
                $this->triggerSecurityAlert($event);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityAuditFailure($eventId, $event, $e);
            throw new AuditException('Security event logging failed', 0, $e);
        }
    }

    public function getAuditTrail(array $criteria): array
    {
        try {
            $this->security->validateSecureOperation('audit:retrieve', $criteria);
            $this->validateAuditCriteria($criteria);

            $trail = $this->retrieveAuditTrail($criteria);
            $this->validateAuditTrail($trail);

            return $trail;

        } catch (\Exception $e) {
            $this->handleAuditRetrievalFailure($criteria, $e);
            throw new AuditException('Audit trail retrieval failed', 0, $e);
        }
    }

    private function createAuditEntry(string $operation, array $context, AuditLevel $level): AuditEntry
    {
        $entry = new AuditEntry();
        $entry->operation = $operation;
        $entry->context = $this->sanitizeContext($context);
        $entry->level = $level;
        $entry->timestamp = time();
        $entry->user_id = $context['user_id'] ?? null;
        $entry->ip_address = $context['ip_address'] ?? null;
        
        return $entry;
    }

    private function processSecurityAudit(AuditEntry $entry): void
    {
        foreach ($this->activeAuditors as $auditor) {
            $auditor->processSecurityEntry($entry);
        }

        if ($entry->severity >= $this->config['alert_threshold']) {
            $this->triggerSecurityAlert($entry);
        }
    }

    private function validateSecurityEvent(SecurityEvent $event): void
    {
        if (!$event->isValid()) {
            throw new AuditException('Invalid security event');
        }

        if (!$this->validateEventContext($event->getContext())) {
            throw new AuditException('Invalid event context');
        }
    }

    private function triggerSecurityAlert(SecurityEvent $event): void
    {
        $alert = new SecurityAlert($event);
        
        foreach ($this->config['alert_channels'] as $channel) {
            try {
                $this->sendAlert($alert, $channel);
            } catch (\Exception $e) {
                $this->logger->error('Alert delivery failed', [
                    'channel' => $channel,
                    'alert' => $alert,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function handleAuditFailure(string $id, string $operation, \Exception $e): void
    {
        $this->logger->critical('Audit operation failed', [
            'audit_id' => $id,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->notifyAuditFailure($id, $operation, $e);
    }

    private function getDefaultConfig(): array
    {
        return [
            'retention_period' => 365 * 86400,
            'alert_threshold' => AuditLevel::HIGH,
            'alert_channels' => ['email', 'sms', 'slack'],
            'max_entry_size' => 1048576,
            'compression_enabled' => true,
            'encryption_enabled' => true
        ];
    }
}
