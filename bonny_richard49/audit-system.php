<?php

namespace App\Core\Audit;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Events\EventDispatcher;
use Illuminate\Support\Facades\DB;

class AuditSystem implements AuditInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private EventDispatcher $events;
    private array $config;
    private array $pendingLogs = [];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        EventDispatcher $events,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->events = $events;
        $this->config = $config;
    }

    public function logAction(string $action, array $data, array $context = []): void
    {
        $entryId = $this->generateEntryId();
        
        try {
            $this->validateLogEntry($action, $data);
            
            $entry = $this->prepareLogEntry($entryId, $action, $data, $context);
            
            if ($this->isCriticalAction($action)) {
                $this->logCriticalAction($entry);
            } else {
                $this->queueLogEntry($entry);
            }

            $this->processSecurityChecks($entry);
            
            if ($this->shouldTriggerEvent($action)) {
                $this->events->dispatch("audit.{$action}", $entry);
            }
            
        } catch (\Throwable $e) {
            $this->handleLoggingFailure($e, $entryId, $action, $data);
        }
    }

    public function logSecurity(array $data): void
    {
        $this->logAction('security_event', $data, ['priority' => 'high']);
    }

    public function logAccess(array $data): void
    {
        $this->logAction('access_event', $data, ['track_ip' => true]);
    }

    public function logSystem(array $data): void
    {
        $this->logAction('system_event', $data, ['system' => true]);
    }

    private function logCriticalAction(array $entry): void
    {
        DB::beginTransaction();
        
        try {
            // Write to main audit log
            $this->writeAuditLog($entry);
            
            // Write to secure backup location
            $this->writeSecureBackup($entry);
            
            // Update security metrics
            $this->updateSecurityMetrics($entry);
            
            DB::commit();
            
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new AuditException('Failed to log critical action', 0, $e);
        }
    }

    private function writeAuditLog(array $entry): void
    {
        DB::table('audit_logs')->insert([
            'id' => $entry['id'],
            'action' => $entry['action'],
            'data' => json_encode($entry['data']),
            'context' => json_encode($entry['context']),
            'metadata' => json_encode($entry['metadata']),
            'created_at' => $entry['timestamp']
        ]);
    }

    private function writeSecureBackup(array $entry): void
    {
        $encryptedEntry = $this->security->encrypt(json_encode($entry));
        
        file_put_contents(
            $this->getSecureBackupPath($entry['id']),
            $encryptedEntry
        );
    }

    private function updateSecurityMetrics(array $entry): void
    {
        $metrics = [
            'action_type' => $entry['action'],
            'timestamp' => $entry['timestamp'],
            'user_id' => $entry['metadata']['user_id'] ?? null,
            'ip_address' => $entry['metadata']['ip_address'] ?? null
        ];

        $this->cache->tags(['audit', 'security'])->put(
            "metrics:{$entry['id']}", 
            $metrics, 
            $this->config['metrics_ttl']
        );
    }

    private function queueLogEntry(array $entry): void
    {
        $this->pendingLogs[] = $entry;
        
        if (count($this->pendingLogs) >= $this->config['batch_size']) {
            $this->flushPendingLogs();
        }
    }

    private function flushPendingLogs(): void
    {
        if (empty($this->pendingLogs)) {
            return;
        }

        DB::beginTransaction();
        
        try {
            foreach ($this->pendingLogs as $entry) {
                $this->writeAuditLog($entry);
            }
            
            DB::commit();
            $this->pendingLogs = [];
            
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new AuditException('Failed to flush pending logs', 0, $e);
        }
    }

    private function validateLogEntry(string $action, array $data): void
    {
        if (!isset($this->config['allowed_actions'][$action])) {
            throw new AuditException("Invalid audit action: {$action}");
        }

        $requiredFields = $this->config['required_fields'][$action] ?? [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new AuditException("Missing required field for {$action}: {$field}");
            }
        }
    }

    private function prepareLogEntry(
        string $id,
        string $action,
        array $data,
        array $context
    ): array {
        return [
            'id' => $id,
            'action' => $action,
            'data' => $data,
            'context' => $context,
            'metadata' => $this->getMetadata(),
            'timestamp' => now()
        ];
    }

    private function getMetadata(): array
    {
        return [
            'user_id' => $this->security->getCurrentUser()?->id,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'session_id' => session()->getId(),
            'request_id' => request()->id()
        ];
    }

    private function generateEntryId(): string
    {
        return sprintf(
            '%s-%s-%s',
            date('YmdHis'),
            substr(md5(uniqid()), 0, 8),
            random_bytes(4)
        );
    }

    private function getSecureBackupPath(string $id): string
    {
        $datePath = date('Y/m/d');
        $path = storage_path("audit/secure/{$datePath}");
        
        if (!is_dir($path)) {
            mkdir($path, 0750, true);
        }
        
        return "{$path}/{$id}.log";
    }

    private function isCriticalAction(string $action): bool
    {
        return in_array($action, $this->config['critical_actions']);
    }

    private function shouldTriggerEvent(string $action): bool
    {
        return in_array($action, $this->config['event_actions']);
    }

    private function processSecurityChecks(array $entry): void
    {
        if ($this->detectsSuspiciousPattern($entry)) {
            $this->security->triggerAlert('suspicious_activity', $entry);
        }

        if ($this->exceedsThreshold($entry)) {
            $this->security->triggerAlert('threshold_exceeded', $entry);
        }
    }

    private function detectsSuspiciousPattern(array $entry): bool
    {
        foreach ($this->config['suspicious_patterns'] as $pattern) {
            if (preg_match($pattern, json_encode($entry))) {
                return true;
            }
        }
        return false;
    }

    private function exceedsThreshold(array $entry): bool
    {
        $key = "audit:rate:{$entry['action']}:" . date('YmdH');
        $count = $this->cache->increment($key);
        
        if ($count === 1) {
            $this->cache->expire($key, 3600);
        }
        
        return $count > ($this->config['thresholds'][$entry['action']] ?? PHP_INT_MAX);
    }

    private function handleLoggingFailure(
        \Throwable $e,
        string $entryId,
        string $action,
        array $data
    ): void {
        $context = [
            'entry_id' => $entryId,
            'action' => $action,
            'data' => $data,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];

        error_log(json_encode($context));
        
        if ($this->isCriticalAction($action)) {
            $this->security->triggerAlert('audit_failure', $context);
            throw $e;
        }
    }
}
