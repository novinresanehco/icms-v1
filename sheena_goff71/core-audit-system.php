<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\{
    AuditManagerInterface,
    StorageInterface
};
use App\Core\Exceptions\{
    AuditException,
    SecurityException,
    StorageException
};

class AuditManager implements AuditManagerInterface
{
    private SecurityManager $security;
    private StorageInterface $storage;
    private array $config;
    private array $activeMonitors = [];

    private const CRITICAL_EVENTS = [
        'security_breach',
        'data_violation',
        'system_failure',
        'unauthorized_access'
    ];

    public function __construct(
        SecurityManager $security,
        StorageInterface $storage,
        array $config
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function recordEvent(string $type, array $data, array $context): void
    {
        $this->security->executeSecureOperation(function() use ($type, $data, $context) {
            $event = $this->prepareEvent($type, $data, $context);
            $this->validateEvent($event);
            
            if ($this->isCriticalEvent($type)) {
                $this->handleCriticalEvent($event);
            }
            
            $this->storeEvent($event);
            $this->notifyMonitors($event);
            
            if ($this->requiresImmediate($type)) {
                $this->processImmediately($event);
            }
        }, $context);
    }

    public function startMonitoring(string $type, array $config, array $context): string
    {
        return $this->security->executeSecureOperation(function() use ($type, $config, $context) {
            $monitorId = $this->generateMonitorId();
            
            $monitor = [
                'id' => $monitorId,
                'type' => $type,
                'config' => $config,
                'context' => $context,
                'started_at' => microtime(true),
                'status' => 'active'
            ];
            
            $this->validateMonitor($monitor);
            $this->activeMonitors[$monitorId] = $monitor;
            
            $this->recordEvent('monitor_start', $monitor, $context);
            return $monitorId;
        }, $context);
    }

    public function stopMonitoring(string $monitorId, array $context): void
    {
        $this->security->executeSecureOperation(function() use ($monitorId, $context) {
            if (!isset($this->activeMonitors[$monitorId])) {
                throw new AuditException('Monitor not found');
            }
            
            $monitor = $this->activeMonitors[$monitorId];
            $monitor['ended_at'] = microtime(true);
            $monitor['duration'] = $monitor['ended_at'] - $monitor['started_at'];
            $monitor['status'] = 'completed';
            
            $this->recordEvent('monitor_stop', $monitor, $context);
            unset($this->activeMonitors[$monitorId]);
        }, $context);
    }

    public function captureMetrics(array $context): array
    {
        return $this->security->executeSecureOperation(function() use ($context) {
            $metrics = [
                'system' => $this->captureSystemMetrics(),
                'performance' => $this->capturePerformanceMetrics(),
                'security' => $this->captureSecurityMetrics(),
                'timestamp' => microtime(true)
            ];
            
            $this->recordEvent('metrics_capture', $metrics, $context);
            return $metrics;
        }, $context);
    }

    protected function prepareEvent(string $type, array $data, array $context): array
    {
        return [
            'type' => $type,
            'data' => $data,
            'context' => $this->sanitizeContext($context),
            'timestamp' => microtime(true),
            'process_id' => getmypid(),
            'memory_usage' => memory_get_usage(true),
            'hash' => $this->generateEventHash($type, $data)
        ];
    }

    protected function validateEvent(array $event): void
    {
        if (!isset($event['type']) || !isset($event['data'])) {
            throw new AuditException('Invalid event structure');
        }

        if ($this->isCriticalEvent($event['type'])) {
            $this->validateCriticalEvent($event);
        }
    }

    protected function validateMonitor(array $monitor): void
    {
        if (!isset($monitor['type']) || !isset($monitor['config'])) {
            throw new AuditException('Invalid monitor configuration');
        }

        if (!$this->isValidMonitorType($monitor['type'])) {
            throw new AuditException('Invalid monitor type');
        }
    }

    protected function handleCriticalEvent(array $event): void
    {
        $this->notifySecurityTeam($event);
        $this->createSecuritySnapshot();
        $this->initiateEmergencyProtocols($event);
    }

    protected function storeEvent(array $event): void
    {
        try {
            $this->storage->store([
                'type' => $event['type'],
                'data' => json_encode($event['data']),
                'context' => json_encode($event['context']),
                'timestamp' => $event['timestamp'],
                'hash' => $event['hash']
            ]);
        } catch (StorageException $e) {
            Log::emergency('Failed to store audit event', [
                'event' => $event,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function notifyMonitors(array $event): void
    {
        foreach ($this->activeMonitors as $monitor) {
            if ($this->shouldNotifyMonitor($monitor, $event)) {
                $this->notifyMonitor($monitor, $event);
            }
        }
    }

    protected function shouldNotifyMonitor(array $monitor, array $event): bool
    {
        return $monitor['status'] === 'active' &&
               ($monitor['config']['event_types'] ?? []) === ['*'] ||
               in_array($event['type'], $monitor['config']['event_types'] ?? []);
    }

    protected function notifyMonitor(array $monitor, array $event): void
    {
        if (isset($monitor['config']['callback'])) {
            try {
                call_user_func($monitor['config']['callback'], $event);
            } catch (\Throwable $e) {
                Log::error('Monitor callback failed', [
                    'monitor' => $monitor,
                    'event' => $event,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    protected function captureSystemMetrics(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'load_average' => sys_getloadavg(),
            'disk_usage' => disk_free_space('/'),
            'php_workers' => $this->getActivePhpProcesses()
        ];
    }

    protected function capturePerformanceMetrics(): array
    {
        return [
            'db_stats' => DB::getQueryLog(),
            'cache_hits' => $this->getCacheStats(),
            'response_times' => $this->getAverageResponseTimes(),
            'error_rates' => $this->getErrorRates()
        ];
    }

    protected function captureSecurityMetrics(): array
    {
        return [
            'active_sessions' => $this->getActiveSessions(),
            'failed_logins' => $this->getFailedLogins(),
            'security_events' => $this->getSecurityEvents(),
            'threat_level' => $this->calculateThreatLevel()
        ];
    }

    protected function sanitizeContext(array $context): array
    {
        unset($context['password'], $context['token'], $context['secret']);
        return $context;
    }

    protected function generateEventHash(string $type, array $data): string
    {
        return hash_hmac(
            'sha256',
            $type . json_encode($data),
            $this->config['app_key']
        );
    }

    protected function generateMonitorId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function isValidMonitorType(string $type): bool
    {
        return in_array($type, [
            'system',
            'security',
            'performance',
            'user_activity',
            'error_tracking'
        ]);
    }

    protected function isCriticalEvent(string $type): bool
    {
        return in_array($type, self::CRITICAL_EVENTS);
    }

    protected function requiresImmediate(string $type): bool
    {
        return $this->isCriticalEvent($type) || 
               ($this->config['immediate_events'] ?? []);
    }

    protected function validateCriticalEvent(array $event): void
    {
        if (!isset($event['data']['severity']) || 
            !isset($event['data']['impact'])) {
            throw new AuditException('Invalid critical event data');
        }
    }
}
