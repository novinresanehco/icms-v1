<?php

namespace App\Core\Audit;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Audit\DTO\{AuditEvent, AuditContext};
use Illuminate\Support\Facades\{DB, Log, Cache};

class AuditManager implements AuditInterface
{
    private SecurityManagerInterface $security;
    private EventProcessor $processor;
    private StorageManager $storage;
    private AlertService $alerts;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        EventProcessor $processor,
        StorageManager $storage,
        AlertService $alerts
    ) {
        $this->security = $security;
        $this->processor = $processor;
        $this->storage = $storage;
        $this->alerts = $alerts;
        $this->config = config('audit');
    }

    public function logCriticalEvent(AuditEvent $event): void
    {
        DB::transaction(function() use ($event) {
            try {
                // Process and enrich event data
                $enrichedEvent = $this->processor->enrichEvent($event);
                
                // Store event with integrity check
                $this->storeEvent($enrichedEvent);
                
                // Process alerts if needed
                if ($this->requiresAlert($enrichedEvent)) {
                    $this->processAlerts($enrichedEvent);
                }
                
                // Archive if needed
                if ($this->requiresArchival($enrichedEvent)) {
                    $this->archiveEvent($enrichedEvent);
                }
                
            } catch (\Exception $e) {
                $this->handleAuditFailure($e, $event);
                throw $e;
            }
        });
    }

    public function logSecurityEvent(AuditEvent $event): void
    {
        try {
            // Validate and enrich security context
            $context = $this->createSecurityContext($event);
            $this->security->validateCriticalOperation($context);
            
            // Process security event
            $enrichedEvent = $this->processor->enrichSecurityEvent($event);
            
            // Store with high priority
            $this->storeSecurityEvent($enrichedEvent);
            
            // Immediate alert for security events
            $this->alerts->sendSecurityAlert($enrichedEvent);
            
        } catch (\Exception $e) {
            $this->handleSecurityAuditFailure($e, $event);
            throw $e;
        }
    }

    public function queryAuditLog(array $criteria): array
    {
        try {
            return $this->storage->query(
                $this->buildQueryCriteria($criteria)
            );
        } catch (\Exception $e) {
            Log::error('Audit query failed', [
                'criteria' => $criteria,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function storeEvent(AuditEvent $event): void
    {
        $eventData = $this->prepareEventData($event);
        
        // Store with integrity check
        $hash = $this->calculateEventHash($eventData);
        $eventData['integrity_hash'] = $hash;
        
        $this->storage->store($eventData);
        
        // Update integrity chain if enabled
        if ($this->config['integrity_chain'] ?? false) {
            $this->updateIntegrityChain($hash);
        }
    }

    protected function storeSecurityEvent(AuditEvent $event): void
    {
        $eventData = $this->prepareEventData($event);
        
        // Add additional security metadata
        $eventData['security_level'] = $event->getSecurityLevel();
        $eventData['threat_level'] = $event->getThreatLevel();
        
        // Store with enhanced integrity check
        $hash = $this->calculateSecurityEventHash($eventData);
        $eventData['security_hash'] = $hash;
        
        $this->storage->storeSecurityEvent($eventData);
    }

    protected function prepareEventData(AuditEvent $event): array
    {
        return [
            'type' => $event->getType(),
            'action' => $event->getAction(),
            'user_id' => $event->getUserId(),
            'timestamp' => $event->getTimestamp(),
            'ip_address' => $event->getIpAddress(),
            'data' => $this->sanitizeEventData($event->getData()),
            'metadata' => $event->getMetadata()
        ];
    }

    protected function sanitizeEventData(array $data): array
    {
        // Remove sensitive data
        foreach ($this->config['sensitive_fields'] ?? [] as $field) {
            unset($data[$field]);
        }
        
        // Encrypt fields if needed
        foreach ($this->config['encrypted_fields'] ?? [] as $field) {
            if (isset($data[$field])) {
                $data[$field] = $this->security->encryptSensitiveData($data[$field]);
            }
        }
        
        return $data;
    }

    protected function calculateEventHash(array $eventData): string
    {
        return hash_hmac(
            'sha256',
            json_encode($eventData),
            $this->config['integrity_key']
        );
    }

    protected function calculateSecurityEventHash(array $eventData): string
    {
        // Enhanced security hash with additional factors
        $factors = [
            json_encode($eventData),
            time(),
            $this->getLastSecurityHash()
        ];
        
        return hash_hmac(
            'sha512',
            implode('|', $factors),
            $this->config['security_key']
        );
    }

    protected function updateIntegrityChain(string $hash): void
    {
        $key = 'audit_integrity_chain';
        $chain = Cache::get($key, []);
        
        $chain[] = $hash;
        if (count($chain) > ($this->config['chain_length'] ?? 1000)) {
            array_shift($chain);
        }
        
        Cache::forever($key, $chain);
    }

    protected function getLastSecurityHash(): string
    {
        return Cache::get('last_security_hash', str_repeat('0', 128));
    }

    protected function requiresAlert(AuditEvent $event): bool
    {
        return in_array(
            $event->getType(),
            $this->config['alert_types'] ?? []
        );
    }

    protected function requiresArchival(AuditEvent $event): bool
    {
        return in_array(
            $event->getType(),
            $this->config['archive_types'] ?? []
        );
    }

    protected function processAlerts(AuditEvent $event): void
    {
        $alertLevel = $this->determineAlertLevel($event);
        $this->alerts->send($event, $alertLevel);
    }

    protected function archiveEvent(AuditEvent $event): void
    {
        $this->storage->archive([
            'event' => $event,
            'timestamp' => time(),
            'hash' => $this->calculateEventHash($event->toArray())
        ]);
    }

    protected function determineAlertLevel(AuditEvent $event): string
    {
        foreach ($this->config['alert_levels'] as $level => $criteria) {
            if ($this->matchesAlertCriteria($event, $criteria)) {
                return $level;
            }
        }
        
        return 'info';
    }

    protected function matchesAlertCriteria(AuditEvent $event, array $criteria): bool
    {
        foreach ($criteria as $field => $value) {
            if (!$event->matches($field, $value)) {
                return false;
            }
        }
        return true;
    }

    protected function buildQueryCriteria(array $criteria): array
    {
        return array_merge(
            $criteria,
            ['limit' => min(
                $criteria['limit'] ?? 1000,
                $this->config['max_query_limit'] ?? 5000
            )]
        );
    }

    protected function handleAuditFailure(\Exception $e, AuditEvent $event): void
    {
        Log::error('Audit logging failed', [
            'event' => $event->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleSecurityAuditFailure(\Exception $e, AuditEvent $event): void
    {
        Log::critical('Security audit logging failed', [
            'event' => $event->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        $this->alerts->sendEmergencyAlert([
            'type' => 'AUDIT_FAILURE',
            'event' => $event->toArray(),
            'error' => $e->getMessage()
        ]);
    }

    protected function createSecurityContext(AuditEvent $event): SecurityContext
    {
        return new SecurityContext([
            'operation' => 'audit.security_log',
            'event_type' => $event->getType(),
            'user_id' => $event->getUserId(),
            'ip' => $event->getIpAddress()
        ]);
    }
}
