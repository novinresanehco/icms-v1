<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\{DB, Redis};
use App\Core\Security\SecurityManagerInterface;
use App\Core\Encryption\EncryptionService;

class AuditManager implements AuditManagerInterface
{
    private SecurityManagerInterface $security;
    private EncryptionService $encryption;
    private EventDispatcher $dispatcher;
    private StorageManager $storage;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        EncryptionService $encryption,
        EventDispatcher $dispatcher,
        StorageManager $storage,
        array $config
    ) {
        $this->security = $security;
        $this->encryption = $encryption;
        $this->dispatcher = $dispatcher;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function logSecurityEvent(string $type, array $data, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            function() use ($type, $data, $context) {
                $event = $this->createSecurityEvent($type, $data, $context);
                $this->persistEvent($event);
                $this->notifySecurityEvent($event);
                
                if ($this->isHighRiskEvent($type)) {
                    $this->handleHighRiskEvent($event);
                }
            },
            $context
        );
    }

    public function logAccessAttempt(SecurityContext $context, string $resource, bool $success): void
    {
        $this->security->executeCriticalOperation(
            function() use ($context, $resource, $success) {
                $event = $this->createAccessEvent($context, $resource, $success);
                $this->persistEvent($event);
                
                if (!$success) {
                    $this->handleFailedAccess($event);
                }
                
                $this->updateAccessMetrics($context, $resource, $success);
            },
            $context
        );
    }

    public function logSystemChange(string $component, array $change, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            function() use ($component, $change, $context) {
                $event = $this->createChangeEvent($component, $change, $context);
                $this->persistEvent($event);
                $this->backupConfiguration($component, $change);
                $this->notifySystemChange($event);
            },
            $context
        );
    }

    protected function createSecurityEvent(string $type, array $data, SecurityContext $context): AuditEvent
    {
        return new AuditEvent([
            'id' => $this->generateEventId(),
            'type' => $type,
            'data' => $this->encryption->encrypt($data),
            'context' => $this->sanitizeContext($context),
            'timestamp' => microtime(true),
            'severity' => $this->calculateSeverity($type, $data)
        ]);
    }

    protected function createAccessEvent(SecurityContext $context, string $resource, bool $success): AuditEvent
    {
        return new AuditEvent([
            'id' => $this->generateEventId(),
            'type' => 'access_attempt',
            'data' => $this->encryption->encrypt([
                'resource' => $resource,
                'success' => $success,
                'ip' => $context->getIpAddress(),
                'user_agent' => $context->getUserAgent()
            ]),
            'context' => $this->sanitizeContext($context),
            'timestamp' => microtime(true),
            'severity' => $success ? 'info' : 'warning'
        ]);
    }

    protected function createChangeEvent(string $component, array $change, SecurityContext $context): AuditEvent
    {
        return new AuditEvent([
            'id' => $this->generateEventId(),
            'type' => 'system_change',
            'data' => $this->encryption->encrypt([
                'component' => $component,
                'change' => $change,
                'previous' => $this->getCurrentState($component)
            ]),
            'context' => $this->sanitizeContext($context),
            'timestamp' => microtime(true),
            'severity' => 'critical'
        ]);
    }

    protected function persistEvent(AuditEvent $event): void
    {
        DB::transaction(function() use ($event) {
            $this->storage->store($event);
            $this->updateIndices($event);
            $this->maintainRetention();
        });
    }

    protected function updateIndices(AuditEvent $event): void
    {
        $indices = [
            "audit:type:{$event->type}",
            "audit:severity:{$event->severity}",
            "audit:user:{$event->context['user_id']}",
        ];

        foreach ($indices as $index) {
            Redis::zadd($index, $event->timestamp, $event->id);
        }
    }

    protected function maintainRetention(): void
    {
        $threshold = microtime(true) - ($this->config['retention_period'] ?? 2592000);
        
        foreach ($this->getIndices() as $index) {
            Redis::zremrangebyscore($index, 0, $threshold);
        }
        
        $this->storage->cleanup($threshold);
    }

    protected function handleHighRiskEvent(AuditEvent $event): void
    {
        $this->notifySecurityTeam($event);
        $this->incrementRiskScore($event->context['user_id']);
        $this->updateSecurityState($event);
    }

    protected function handleFailedAccess(AuditEvent $event): void
    {
        $attempts = $this->incrementFailedAttempts(
            $event->context['user_id'],
            $event->context['ip_address']
        );

        if ($attempts >= ($this->config['max_failed_attempts'] ?? 5)) {
            $this->security->blockAccess(
                $event->context['user_id'],
                $event->context['ip_address']
            );
        }
    }

    protected function updateAccessMetrics(SecurityContext $context, string $resource, bool $success): void
    {
        $metrics = [
            "access:total:{$resource}" => 1,
            "access:" . ($success ? 'success' : 'failure') . ":{$resource}" => 1,
            "access:user:{$context->getUserId()}:{$resource}" => 1
        ];

        foreach ($metrics as $key => $increment) {
            Redis::hincrby($key, date('Y-m-d-H'), $increment);
        }
    }

    protected function calculateSeverity(string $type, array $data): string
    {
        return match ($type) {
            'security_breach' => 'critical',
            'authentication_failure' => 'warning',
            'configuration_change' => 'notice',
            default => 'info'
        };
    }

    protected function generateEventId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function sanitizeContext(SecurityContext $context): array
    {
        return array_filter([
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'user_agent' => $context->getUserAgent(),
            'session_id' => $context->getSessionId()
        ]);
    }
}
