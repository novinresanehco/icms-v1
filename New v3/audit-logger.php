<?php

namespace App\Core\Logging;

use App\Core\Security\SecurityManager;
use App\Core\Metrics\MetricsCollector;
use App\Core\Storage\StorageManager;
use App\Core\Encryption\EncryptionService;

class AuditLogger implements AuditInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private StorageManager $storage;
    private EncryptionService $encryption;
    private array $config;

    public function log(string $event, array $data, string $level = self::LEVEL_INFO): void 
    {
        $logId = $this->generateLogId();
        
        try {
            $this->validateLogRequest($event, $data, $level);
            $this->security->validateAccess('audit.log');

            $entry = $this->createLogEntry($logId, $event, $data, $level);
            $this->storeLogEntry($entry);
            
            if ($this->isHighPriorityLog($level)) {
                $this->processHighPriorityLog($entry);
            }

        } catch (\Exception $e) {
            $this->handleLogFailure($e, $event, $data, $level);
            throw $e;
        }
    }

    public function logSecurity(string $event, array $data): void
    {
        try {
            $this->security->validateAccess('audit.security');
            
            $securityData = array_merge($data, [
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'timestamp' => microtime(true)
            ]);
            
            $this->log($event, $securityData, self::LEVEL_SECURITY);
            
        } catch (\Exception $e) {
            $this->handleSecurityLogFailure($e, $event, $data);
            throw $e;
        }
    }

    public function logSystemFailure(string $component, array $data): void
    {
        try {
            $this->security->validateAccess('audit.system');
            
            $failureData = array_merge($data, [
                'component' => $component,
                'system_state' => $this->captureSystemState(),
                'timestamp' => microtime(true)
            ]);
            
            $this->log('system_failure', $failureData, self::LEVEL_CRITICAL);
            
        } catch (\Exception $e) {
            $this->handleSystemLogFailure($e, $component, $data);
            throw $e;
        }
    }

    public function logAccess(string $resource, string $action, array $context = []): void
    {
        try {
            $this->security->validateAccess('audit.access');
            
            $accessData = array_merge($context, [
                'resource' => $resource,
                'action' => $action,
                'user_id' => auth()->id(),
                'ip' => request()->ip(),
                'timestamp' => microtime(true)
            ]);
            
            $this->log('access', $accessData, self::LEVEL_SECURITY);
            
        } catch (\Exception $e) {
            $this->handleAccessLogFailure($e, $resource, $action, $context);
            throw $e;
        }
    }

    public function query(): AuditQuery
    {
        try {
            $this->security->validateAccess('audit.query');
            return new AuditQuery($this->storage);
        } catch (\Exception $e) {
            $this->handleQueryFailure($e);
            throw $e;
        }
    }

    protected function createLogEntry(string $id, string $event, array $data, string $level): array
    {
        return [
            'id' => $id,
            'event' => $event,
            'data' => $data,
            'level' => $level,
            'timestamp' => microtime(true),
            'environment' => config('app.env'),
            'process_id' => getmypid(),
            'memory_usage' => memory_get_usage(true),
            'request_id' => request()->id()
        ];
    }

    protected function storeLogEntry(array $entry): void
    {
        $encrypted = $this->encryption->encrypt(json_encode($entry));
        
        DB::transaction(function() use ($entry, $encrypted) {
            $this->storage->store('audit_logs', [
                'id' => $entry['id'],
                'event' => $entry['event'],
                'level' => $entry['level'],
                'data' => $encrypted,
                'created_at' => $entry['timestamp']
            ]);
            
            $this->updateMetrics($entry);
        });
    }

    protected function processHighPriorityLog(array $entry): void
    {
        if ($entry['level'] === self::LEVEL_CRITICAL) {
            $this->notifyAdministrators($entry);
        }

        if ($this->requiresImmediateAction($entry)) {
            $this->triggerEmergencyProtocol($entry);
        }

        $this->archiveHighPriorityLog($entry);
    }

    protected function updateMetrics(array $entry): void
    {
        $this->metrics->increment('audit_logs_total');
        $this->metrics->increment("audit_logs_by_level.{$entry['level']}");
        $this->metrics->increment("audit_logs_by_event.{$entry['event']}");
    }

    protected function validateLogRequest(string $event, array $data, string $level): void
    {
        if (empty($event)) {
            throw new AuditException('Event name cannot be empty');
        }

        if (!in_array($level, [
            self::LEVEL_DEBUG,
            self::LEVEL_INFO,
            self::LEVEL_WARNING,
            self::LEVEL_ERROR,
            self::LEVEL_CRITICAL,
            self::LEVEL_SECURITY
        ])) {
            throw new AuditException('Invalid log level');
        }
    }

    protected function isHighPriorityLog(string $level): bool
    {
        return in_array($level, [
            self::LEVEL_CRITICAL,
            self::LEVEL_SECURITY
        ]);
    }

    protected function requiresImmediateAction(array $entry): bool
    {
        return $entry['level'] === self::LEVEL_CRITICAL && 
               isset($this->config['critical_events'][$entry['event']]);
    }

    protected function archiveHighPriorityLog(array $entry): void
    {
        $archivePath = $this->getArchivePath($entry);
        $encryptedEntry = $this->encryption->encrypt(json_encode($entry));
        
        $this->storage->store($archivePath, $encryptedEntry);
    }

    protected function captureSystemState(): array
    {
        return [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg(),
            'disk' => disk_free_space('/'),
            'load' => sys_getloadavg()[0],
            'time' => microtime(true)
        ];
    }

    private function generateLogId(): string
    {
        return 'log_' . md5(uniqid(mt_rand(), true));
    }

    private function getArchivePath(array $entry): string
    {
        $date = date('Y/m/d', $entry['timestamp']);
        return "audit_archive/{$date}/{$entry['level']}/{$entry['id']}.log";
    }
}
